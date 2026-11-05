<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\PickupToken;
use App\Delivery\Enum\DeliveryStatus;
use App\Delivery\Entity\Delivery;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-21: "I miei acquisti". Gruppo di prova: minimo 10 unità (10 → 9,00 €, 20 → 8,00 €), commissione 10%,
 * listino 12,00 €; l'adesione è di 3 unità con acconto di 5,40 €.
 */
final class PurchasesTest extends WebTestCase
{
    use GroupFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->startFixtures($this->em);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testThePageNeedsALogin(): void
    {
        $this->client->request('GET', '/api/me/purchases');

        self::assertResponseStatusCodeSame(401);
    }

    public function testSomeoneWithoutParticipationsGetsAnEmptyList(): void
    {
        $this->client->loginUser($this->plainUser());

        $data = $this->purchases();

        self::assertSame('in-corso', $data['tab']);
        self::assertSame('in-corso', $data['tab_predefinito']);
        self::assertSame(['in-corso' => 0, 'in-arrivo' => 0, 'conclusi' => 0, 'annullati' => 0], $data['conteggi']);
        self::assertSame([], $data['acquisti']);
    }

    public function testAnOpenGroupBelowTheMinimumShowsGoalEstimateAndWithdrawal(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($member->getUser());

        $data = $this->purchases();

        self::assertSame(['in-corso' => 1, 'in-arrivo' => 0, 'conclusi' => 0, 'annullati' => 0], $data['conteggi']);
        self::assertSame(['gruppi_in_corso' => 1, 'ritiri_pronti' => 0], $data['riepilogo']);
        $row = $data['acquisti'][0];
        self::assertSame($member->getId()->toRfc4122(), $row['id']);
        self::assertSame('in_corso', $row['vista']);
        self::assertSame('Gruppo in corso', $row['etichetta']);
        self::assertSame(3, $row['passo']);
        self::assertTrue($row['puo_annullare']);
        self::assertFalse($row['annullo_bloccato']);
        self::assertTrue($row['puo_aggiornare_metodo']);
        self::assertFalse($row['puo_aumentare']);
        self::assertSame(540, $row['acconto_cents']);
        self::assertSame(['tipo' => 'attivazione', 'soglia' => 10, 'mancano' => 4, 'prezzo_cents' => 900, 'pct' => 0.6], $row['gruppo']['obiettivo']);
        self::assertSame(6, $row['gruppo']['quantita_attuale']);
        self::assertSame(10, $row['gruppo']['minimo']);
        // Stima: 3 × 9,00 = 27,00 + commissione 10% (2,70) − acconto 5,40 = 24,30.
        self::assertSame(2430, $row['saldo_previsto_cents']);
        self::assertSame(
            ['merce_cents' => 2700, 'commissione_cents' => 270, 'spedizione_cents' => 0, 'totale_cents' => 2970, 'acconto_cents' => 540, 'saldo_cents' => 2430, 'stimato' => true, 'spedizione_scelta' => false],
            $row['saldo_dettaglio'],
        );
        self::assertGreaterThan(0, $row['gruppo']['scade_tra_secondi']);
    }

    public function testOnceTheMinimumIsReachedTheGroupIsActiveAndLocked(): void
    {
        $campaign = $this->group($this->em, quantity: 12, deadline: '+3 days');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Confermata, home: true);
        $this->client->loginUser($member->getUser());

        $row = $this->purchases()['acquisti'][0];

        self::assertSame('attivo', $row['vista']);
        self::assertFalse($row['puo_annullare']);
        self::assertTrue($row['annullo_bloccato']);
        self::assertFalse($row['chiusura_in_corso']);
        self::assertSame('prezzo', $row['gruppo']['obiettivo']['tipo']);
        self::assertSame(20, $row['gruppo']['obiettivo']['soglia']);
        // Con la consegna a domicilio la stima aggiunge i 4,90 € di spedizione: 24,30 + 4,90 = 29,20.
        self::assertSame(2920, $row['saldo_previsto_cents']);
        self::assertSame(490, $row['saldo_dettaglio']['spedizione_cents']);
        self::assertTrue($row['saldo_dettaglio']['spedizione_scelta']);
    }

    public function testAnExpiredButActiveGroupIsMarkedAsClosing(): void
    {
        $campaign = $this->group($this->em, quantity: 12, deadline: '-1 hour');
        $member = $this->member($this->em, $campaign, ParticipationStatus::Confermata);
        $this->client->loginUser($member->getUser());

        $row = $this->purchases()['acquisti'][0];

        self::assertSame('attivo', $row['vista']);
        self::assertTrue($row['chiusura_in_corso']);
        self::assertSame(0, $row['gruppo']['scade_tra_secondi']);
    }

    public function testAFailedBalanceShowsTheDeadlineAndComesFirst(): void
    {
        $user = $this->plainUser();
        $open = $this->group($this->em, quantity: 6, deadline: '+1 day');
        $closed = $this->group($this->em, quantity: 12, deadline: '-2 days', status: CampaignStatus::OrdinePronto);
        $this->join($user, $open, ParticipationStatus::Prenotata);
        $failed = $this->join($user, $closed, ParticipationStatus::AzioneRichiesta);
        $failed->setBalanceDueAt(new \DateTimeImmutable('+1 day'));
        $this->em->flush();
        $this->client->loginUser($user);

        $data = $this->purchases();

        self::assertSame(2, $data['conteggi']['in-corso']);
        self::assertSame('pagamento', $data['acquisti'][0]['vista'], 'Il pagamento da sistemare sta in cima.');
        self::assertSame('Pagamento non riuscito', $data['acquisti'][0]['etichetta']);
        self::assertSame(4, $data['acquisti'][0]['passo']);
        self::assertNotNull($data['acquisti'][0]['saldo_scadenza']);
        self::assertSame('in_corso', $data['acquisti'][1]['vista']);
        self::assertNull($data['acquisti'][1]['saldo_scadenza']);
    }

    public function testAPaidOrderIsInTransitWithTheTotalPaidAndTheSaving(): void
    {
        $campaign = $this->group($this->em, quantity: 22, deadline: '-2 days', status: CampaignStatus::OrdineFornitore);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $this->em->persist((new Payment($member, PaymentType::Saldo, 2430))->setStatus(PaymentStatus::Confermato)->setConfirmedAt(new \DateTimeImmutable()));
        $this->em->persist((new Payment($member, PaymentType::Saldo, 999))->setStatus(PaymentStatus::Rimborsato));
        $this->em->flush();
        $this->client->loginUser($member->getUser());

        $data = $this->purchases();

        self::assertSame('in-arrivo', $data['tab']);
        $row = $data['acquisti'][0];
        self::assertSame('inviato', $row['vista']);
        self::assertSame(5, $row['passo']);
        // 5,40 + 24,30: il pagamento rimborsato non conta.
        self::assertSame(2970, $row['totale_pagato_cents']);
        // Listino 12,00 €, prezzo applicato 8,00 € (22 unità), 3 unità: 12,00 € risparmiati.
        self::assertSame(1200, $row['risparmiato_cents']);
        self::assertFalse($row['qr_disponibile']);
        self::assertArrayNotHasKey('ritiro', $row);
    }

    public function testAReadyPickupShowsThePointAndComesBeforeOtherOrders(): void
    {
        $user = $this->plainUser();
        $ordered = $this->group($this->em, quantity: 22, deadline: '-3 days', status: CampaignStatus::OrdineFornitore);
        $arrived = $this->group($this->em, quantity: 22, deadline: '-4 days', status: CampaignStatus::Consegnata);
        $this->join($user, $ordered, ParticipationStatus::Pagata);
        $ready = $this->join($user, $arrived, ParticipationStatus::Pagata);
        $this->em->persist(new PickupToken($ready, hash('sha256', 'qr-'.$ready->getId()->toRfc4122()), 3));
        $this->em->flush();
        $this->client->loginUser($user);

        $data = $this->purchases();

        self::assertSame(['gruppi_in_corso' => 0, 'ritiri_pronti' => 1], $data['riepilogo']);
        $row = $data['acquisti'][0];
        self::assertSame($ready->getId()->toRfc4122(), $row['id']);
        self::assertSame('pronto', $row['vista']);
        self::assertSame('Pronto per il ritiro', $row['etichetta']);
        self::assertSame(6, $row['passo']);
        self::assertTrue($row['qr_disponibile']);
        self::assertSame('Sede chiusura test', $row['ritiro']['sede_nome']);
        self::assertSame('Perugia', $row['ritiro']['sede_citta']);
        self::assertSame('inviato', $data['acquisti'][1]['vista']);
    }

    public function testACollectedOrderIsConcludedAndAHomeDeliveryIsLabelledDelivered(): void
    {
        $user = $this->plainUser();
        $pickup = $this->group($this->em, quantity: 22, deadline: '-4 days', status: CampaignStatus::Consegnata);
        $home = $this->group($this->em, quantity: 22, deadline: '-5 days', status: CampaignStatus::Consegnata);
        $collected = $this->join($user, $pickup, ParticipationStatus::Pagata);
        $delivered = $this->join($user, $home, ParticipationStatus::Pagata, home: true);
        $this->em->persist((new PickupToken($collected, hash('sha256', 'qr-ritirato'), 3))->markScanned());
        $this->em->getRepository(Delivery::class)->findOneBy(['participation' => $delivered])
            ->setStatus(DeliveryStatus::Consegnata)->setDeliveredAt(new \DateTimeImmutable('-1 day'));
        $this->em->flush();
        $this->client->loginUser($user);

        $data = $this->purchases(['tab' => 'conclusi']);

        self::assertSame('conclusi', $data['tab']);
        self::assertSame(2, $data['conteggi']['conclusi']);
        $byId = array_column($data['acquisti'], null, 'id');
        self::assertSame('Ritirato', $byId[$collected->getId()->toRfc4122()]['etichetta']);
        self::assertTrue($byId[$collected->getId()->toRfc4122()]['concluso']);
        self::assertNotNull($byId[$collected->getId()->toRfc4122()]['ritiro']['data']);
        self::assertSame('Consegnato', $byId[$delivered->getId()->toRfc4122()]['etichetta']);
        self::assertTrue($byId[$delivered->getId()->toRfc4122()]['spedizione']);
        self::assertSame(['prodotto_fatta' => false, 'fornitore_fatta' => false], $byId[$collected->getId()->toRfc4122()]['recensione']);
    }

    public function testAShippedHomeDeliveryOffersTheReceiptConfirmation(): void
    {
        $campaign = $this->group($this->em, quantity: 22, deadline: '-4 days', status: CampaignStatus::Consegnata);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Pagata, home: true);
        $delivery = $this->em->getRepository(Delivery::class)->findOneBy(['participation' => $member]);
        $delivery->setStatus(DeliveryStatus::Spedita);
        $this->em->flush();
        $this->client->loginUser($member->getUser());

        $row = $this->purchases()['acquisti'][0];

        self::assertSame('inviato', $row['vista']);
        self::assertTrue($row['puo_confermare_ricezione']);
        self::assertSame($delivery->getId()->toRfc4122(), $row['id_consegna']);
        self::assertSame('consegna_domicilio', $row['consegna_modalita']);
    }

    public function testRefundedAndCancelledParticipationsLandInTheCancelledTab(): void
    {
        $user = $this->plainUser();
        $failedGroup = $this->group($this->em, quantity: 4, deadline: '-1 day', status: CampaignStatus::Fallita);
        $expiredBelowMinimum = $this->group($this->em, quantity: 4, deadline: '-1 hour');
        $refunded = $this->group($this->em, quantity: 4, deadline: '-3 days', status: CampaignStatus::Fallita);
        $withdrawn = $this->group($this->em, quantity: 4, deadline: '+3 days');
        $processing = $this->join($user, $failedGroup, ParticipationStatus::Prenotata);
        $waiting = $this->join($user, $expiredBelowMinimum, ParticipationStatus::Prenotata);
        $done = $this->join($user, $refunded, ParticipationStatus::Rimborsata);
        $mine = $this->join($user, $withdrawn, ParticipationStatus::Annullata);
        $this->client->loginUser($user);

        $data = $this->purchases(['tab' => 'annullati']);

        self::assertSame(4, $data['conteggi']['annullati']);
        $byId = array_column($data['acquisti'], null, 'id');
        $row = $byId[$processing->getId()->toRfc4122()];
        self::assertSame('rimborsato', $row['vista']);
        self::assertTrue($row['rimborso']['in_elaborazione']);
        self::assertNull($row['rimborso']['data']);
        self::assertSame(540, $row['rimborso']['importo_cents']);
        self::assertSame(4, $row['rimborso']['pezzi']);
        self::assertSame(10, $row['rimborso']['minimo']);
        self::assertTrue($byId[$waiting->getId()->toRfc4122()]['rimborso']['in_elaborazione'], 'Scaduto sotto il minimo: il rimborso è in arrivo.');
        self::assertFalse($byId[$done->getId()->toRfc4122()]['rimborso']['in_elaborazione']);
        self::assertSame('annullato', $byId[$mine->getId()->toRfc4122()]['vista']);
        self::assertSame(0, $byId[$mine->getId()->toRfc4122()]['passo']);
    }

    public function testTheDefaultTabIsTheFirstNonEmptyOneAndInvalidParametersFallBack(): void
    {
        $campaign = $this->group($this->em, quantity: 22, deadline: '-2 days', status: CampaignStatus::OrdineFornitore);
        $member = $this->member($this->em, $campaign, ParticipationStatus::Pagata);
        $this->client->loginUser($member->getUser());

        $data = $this->purchases();
        self::assertSame('in-arrivo', $data['tab']);
        self::assertSame('in-arrivo', $data['tab_predefinito']);
        self::assertSame('recenti', $data['sort']);

        $data = $this->purchases(['tab' => 'inventato', 'sort' => 'a-caso']);
        self::assertSame('in-arrivo', $data['tab']);
        self::assertSame('recenti', $data['sort']);

        $data = $this->purchases(['tab' => 'in-corso', 'sort' => 'a-caso']);
        self::assertSame('in-corso', $data['tab']);
        self::assertSame('scadenza', $data['sort']);
        self::assertSame([], $data['acquisti']);

        $data = $this->purchases(['id' => $member->getId()->toRfc4122()]);
        self::assertSame('in-arrivo', $data['tab']);
    }

    public function testTheOpenTabIsSortedByDeadlineOrByRecency(): void
    {
        $user = $this->plainUser();
        $far = $this->group($this->em, quantity: 6, deadline: '+5 days');
        $near = $this->group($this->em, quantity: 6, deadline: '+1 day');
        $first = $this->join($user, $far, ParticipationStatus::Prenotata);
        $second = $this->join($user, $near, ParticipationStatus::Prenotata);
        $this->client->loginUser($user);

        $byDeadline = $this->purchases(['tab' => 'in-corso']);
        self::assertSame('scadenza', $byDeadline['sort']);
        self::assertSame([$second->getId()->toRfc4122(), $first->getId()->toRfc4122()], array_column($byDeadline['acquisti'], 'id'));

        $byRecency = $this->purchases(['tab' => 'in-corso', 'sort' => 'recenti']);
        self::assertSame([$second->getId()->toRfc4122(), $first->getId()->toRfc4122()], array_column($byRecency['acquisti'], 'id'));

        $this->em->clear();
    }

    public function testOthersParticipationsAreNeverListed(): void
    {
        $campaign = $this->group($this->em, quantity: 6, deadline: '+3 days');
        $mine = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $theirs = $this->member($this->em, $campaign, ParticipationStatus::Prenotata);
        $this->client->loginUser($mine->getUser());

        $ids = array_column($this->purchases()['acquisti'], 'id');

        self::assertSame([$mine->getId()->toRfc4122()], $ids);
        self::assertNotContains($theirs->getId()->toRfc4122(), $ids);
    }

    private function plainUser(): User
    {
        $user = new User('Cliente', 'u'.++$this->fixtureCounter.'-'.bin2hex(random_bytes(3)).'@purchases-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Adesione di un utente già esistente (member() crea sempre un utente nuovo). */
    private function join(User $user, Campaign $campaign, ParticipationStatus $status, bool $home = false): Participation
    {
        $participation = (new Participation($campaign, $user, 3))->setDepositCents(540)->setStatus($status);
        $delivery = new Delivery($participation, $home ? \App\Delivery\Enum\DeliveryMode::ConsegnaDomicilio : \App\Delivery\Enum\DeliveryMode::RitiroSede);
        $delivery->setDeliveryCostCents($home ? 490 : 0);
        $payment = (new Payment($participation, PaymentType::Acconto, 540))
            ->setStripePaymentIntentId('pi_join_'.++$this->fixtureCounter)
            ->setStatus(PaymentStatus::Confermato)
            ->setConfirmedAt(new \DateTimeImmutable());
        $this->em->persist($participation);
        $this->em->persist($delivery);
        $this->em->persist($payment);
        $this->em->flush();

        return $participation;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array<string, mixed>
     */
    private function purchases(array $query = []): array
    {
        $this->client->request('GET', '/api/me/purchases'.([] === $query ? '' : '?'.http_build_query($query)));
        self::assertResponseStatusCodeSame(200);
        $body = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertTrue($body['ok']);

        return $body['dati'];
    }
}
