<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Enum\DeliveryMode;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Supplier\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * US-25: concorrenza VERA. Due processi PHP distinti, con connessioni al database distinte, partono nello stesso istante
 * sullo stesso gruppo (si dà il via con un file, dopo che entrambi sono pronti). Non c'è una transazione di test che
 * avvolge tutto: i dati sono davvero salvati e poi cancellati. Ogni giro ripete la gara; l'ordine in cui vincono i
 * processi cambia, ma il risultato deve restare coerente in tutti e due i casi.
 *
 * Gruppo: minimo 10 unità; l'adesione A è di 3 unità (acconto 5,40 €) e il gruppo ne ha 8; B versa un acconto di 2 unità.
 */
final class ConcurrencyTest extends KernelTestCase
{
    private const EMAIL_SUFFIX = '@concurrency-test.example';
    private const SUPPLIER = 'Fornitore concorrenza test';
    private const POINT = 'Sede concorrenza test';
    private const ROUNDS = 6;

    private EntityManagerInterface $em;
    private Supplier $supplier;
    private PickupPoint $pickupPoint;
    private int $counter = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->cleanUp(); // resti di una prova interrotta
        // I file dei proxy di Doctrine si generano una volta sola, qui: due processi che li scrivono insieme si pestano i piedi.
        $this->em->getProxyFactory()->generateProxyClasses($this->em->getMetadataFactory()->getAllMetadata());

        $this->supplier = new Supplier(self::SUPPLIER);
        $this->pickupPoint = new PickupPoint(self::POINT, 'Via Prova 1', 'Perugia');
        $this->em->persist($this->supplier);
        $this->em->persist($this->pickupPoint);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->cleanUp();
        parent::tearDown();
    }

    public function testAWithdrawalAndAnArrivingDepositNeverLeaveTheGroupInconsistent(): void
    {
        $outcomes = ['ritiro_prima' => 0, 'acconto_prima' => 0];
        // Il ritiro parte da subito o con qualche millisecondo di ritardo: si provano entrambi gli ordini di arrivo.
        $delays = [0, 25, 60, 90, 130, 180];

        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $campaign = $this->group(quantity: 8);
            $a = $this->member($campaign, quantity: 3);
            $buyer = $this->user();

            [$withdraw, $deposit] = $this->race(
                ['withdraw', ['campaign' => $campaign->getId()->toRfc4122(), 'user' => $a->getUser()->getId()->toRfc4122(), 'delayMs' => $delays[$round - 1]]],
                ['deposit', ['campaign' => $campaign->getId()->toRfc4122(), 'user' => $buyer->getId()->toRfc4122(), 'quantity' => 2, 'amount' => 360, 'pi' => 'pi_race_'.$round.'_'.bin2hex(random_bytes(3)), 'pickupPoint' => $this->pickupPoint->getId()->toRfc4122()]],
            );

            self::assertSame('Created', $deposit['dati'] ?? null, 'Il nuovo acconto diventa sempre un\'adesione: '.json_encode($deposit));

            $this->em->clear();
            $stored = $this->em->find(Campaign::class, $campaign->getId());
            $mine = $this->em->find(Participation::class, $a->getId());
            $theirs = $this->em->getRepository(Participation::class)->findOneBy(['campaign' => $campaign->getId(), 'user' => $buyer->getId()]);
            $payment = $this->em->getRepository(Payment::class)->findOneBy(['participation' => $a->getId()]);
            self::assertNotNull($stored);
            self::assertNotNull($mine);
            self::assertNotNull($theirs);
            self::assertNotNull($payment);

            if (ParticipationStatus::Annullata === $mine->getStatus()) {
                // Ha vinto il ritiro: 8 − 3 + 2 = 7 unità, il minimo non è raggiunto, nessuna conferma.
                ++$outcomes['ritiro_prima'];
                self::assertTrue($withdraw['ok'], json_encode($withdraw));
                self::assertSame(7, $stored->getCurrentQuantity());
                self::assertSame(CampaignStatus::InCorso, $stored->getStatus());
                self::assertSame(ParticipationStatus::Prenotata, $theirs->getStatus());
                self::assertSame(PaymentStatus::Rimborsato, $payment->getStatus());
            } else {
                // Ha vinto l'acconto: 8 + 2 = 10 unità, minimo raggiunto, il ritiro non è più possibile.
                ++$outcomes['acconto_prima'];
                self::assertFalse($withdraw['ok'], json_encode($withdraw));
                self::assertSame('ANNULLO_NON_CONSENTITO', $withdraw['codice']);
                self::assertSame(10, $stored->getCurrentQuantity());
                self::assertSame(CampaignStatus::Riuscita, $stored->getStatus());
                self::assertSame(ParticipationStatus::Confermata, $mine->getStatus());
                self::assertSame(ParticipationStatus::Confermata, $theirs->getStatus());
                self::assertSame(PaymentStatus::Confermato, $payment->getStatus());
            }
        }

        fwrite(\STDERR, \sprintf("\n[concorrenza] ritiro prima: %d, acconto prima: %d\n", $outcomes['ritiro_prima'], $outcomes['acconto_prima']));
        self::assertSame(self::ROUNDS, array_sum($outcomes));
    }

    public function testTheSameWithdrawalSentTwiceAtOnceTakesTheUnitsOutOnlyOnce(): void
    {
        for ($round = 1; $round <= self::ROUNDS; ++$round) {
            $campaign = $this->group(quantity: 6);
            $a = $this->member($campaign, quantity: 3);
            $params = ['campaign' => $campaign->getId()->toRfc4122(), 'user' => $a->getUser()->getId()->toRfc4122()];

            [$first, $second] = $this->race(['withdraw', $params], ['withdraw', $params]);

            $results = [$first, $second];
            $done = array_values(array_filter($results, static fn (array $r): bool => $r['ok']));
            $refused = array_values(array_filter($results, static fn (array $r): bool => !$r['ok']));
            self::assertCount(1, $done, 'Un solo ritiro va a buon fine: '.json_encode($results));
            self::assertCount(1, $refused);
            self::assertSame('NON_PARTECIPI', $refused[0]['codice']);

            $this->em->clear();
            self::assertSame(3, $this->em->find(Campaign::class, $campaign->getId())?->getCurrentQuantity(), 'Le unità tornano indietro una volta sola.');
            self::assertSame(ParticipationStatus::Annullata, $this->em->find(Participation::class, $a->getId())?->getStatus());
        }
    }

    /* ------------------------------ dati e processi ------------------------------ */

    private function group(int $quantity): Campaign
    {
        // Dopo clear() fornitore e sede vanno riletti: altrimenti Doctrine li considera nuovi.
        $supplier = $this->em->find(Supplier::class, $this->supplier->getId()) ?? self::fail('Fornitore di prova sparito.');
        $point = $this->em->find(PickupPoint::class, $this->pickupPoint->getId()) ?? self::fail('Sede di prova sparita.');
        $product = (new Product($supplier, 'Prodotto concorrenza '.++$this->counter, 1000))->setBasePriceCents(1200);
        $campaign = new Campaign($product, 10, new \DateTimeImmutable('+3 days'), 1200, 900);
        $campaign->setPickupPoint($point)->setCurrentQuantity($quantity);
        $campaign->addPriceTier(new PriceTier($campaign, 10, 900));
        $campaign->addPriceTier(new PriceTier($campaign, 20, 800));
        $this->em->persist($product);
        $this->em->persist($campaign);
        $this->em->flush();

        return $campaign;
    }

    private function user(): User
    {
        $user = new User('Cliente', 'c'.++$this->counter.'-'.bin2hex(random_bytes(3)).self::EMAIL_SUFFIX);
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /** Adesione "prenotata" con acconto già pagato (5,40 € per 3 unità). */
    private function member(Campaign $campaign, int $quantity): Participation
    {
        $user = $this->user();
        $participation = (new Participation($campaign, $user, $quantity))->setDepositCents(540)->setStatus(ParticipationStatus::Prenotata);
        $this->em->persist($participation);
        $this->em->persist(new Delivery($participation, DeliveryMode::RitiroSede));
        $this->em->persist((new Payment($participation, PaymentType::Acconto, 540))
            ->setStripePaymentIntentId('pi_member_'.$this->counter.'_'.bin2hex(random_bytes(3)))
            ->setStripePaymentMethod('pm_test')
            ->setStatus(PaymentStatus::Confermato)
            ->setConfirmedAt(new \DateTimeImmutable()));
        $this->em->flush();

        return $participation;
    }

    /**
     * Due processi che partono insieme. Restituisce i loro risultati nell'ordine in cui sono stati passati.
     *
     * @param array{0: string, 1: array<string, mixed>} $first
     * @param array{0: string, 1: array<string, mixed>} $second
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function race(array $first, array $second): array
    {
        $dir = sys_get_temp_dir();
        $token = bin2hex(random_bytes(4));
        $go = $dir.'/concurrency-go-'.$token;
        $workers = [];
        foreach ([$first, $second] as $index => [$action, $params]) {
            $ready = $dir.'/concurrency-ready-'.$token.'-'.$index;
            $process = proc_open(
                [\PHP_BINARY, __DIR__.'/../Support/concurrency_worker.php', $action, $go, $ready, json_encode($params, \JSON_THROW_ON_ERROR)],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['APP_ENV' => 'test'] + getenv(),
            );
            self::assertIsResource($process, 'Processo figlio non avviato.');
            $workers[] = ['process' => $process, 'pipes' => $pipes, 'ready' => $ready];
        }

        $limit = microtime(true) + 60;
        while (array_filter($workers, static fn (array $w): bool => !file_exists($w['ready'])) !== []) {
            if (microtime(true) > $limit) {
                break;
            }
            usleep(20000);
        }
        touch($go);

        $results = [];
        foreach ($workers as $worker) {
            $output = (string) stream_get_contents($worker['pipes'][1]);
            $errors = (string) stream_get_contents($worker['pipes'][2]);
            proc_close($worker['process']);
            @unlink($worker['ready']);
            // Il risultato è l'unica riga JSON dell'output (eventuali avvisi di PHP la precedono).
            $decoded = 1 === preg_match('/\{"azione".*\}/s', $output, $found) ? json_decode($found[0], true) : null;
            self::assertIsArray($decoded, 'Il processo figlio non ha risposto. Output: '.$output.' Errori: '.$errors);
            $results[] = $decoded;
        }
        @unlink($go);

        return [$results[0], $results[1]];
    }

    /** Cancella tutto ciò che questo test ha salvato (riconoscibile da nomi ed email di prova). */
    private function cleanUp(): void
    {
        $db = $this->em->getConnection();
        $mail = '%'.self::EMAIL_SUFFIX;
        $db->executeStatement('SET FOREIGN_KEY_CHECKS=0');
        try {
            $db->executeStatement('DELETE n FROM notifications n JOIN users u ON u.id = n.user_id WHERE u.email LIKE ?', [$mail]);
            $db->executeStatement('DELETE pay FROM payments pay JOIN participations p ON p.id = pay.participation_id JOIN users u ON u.id = p.user_id WHERE u.email LIKE ?', [$mail]);
            $db->executeStatement('DELETE d FROM deliveries d JOIN participations p ON p.id = d.participation_id JOIN users u ON u.id = p.user_id WHERE u.email LIKE ?', [$mail]);
            $db->executeStatement('DELETE p FROM participations p JOIN users u ON u.id = p.user_id WHERE u.email LIKE ?', [$mail]);
            $db->executeStatement('DELETE t FROM price_tiers t JOIN campaigns c ON c.id = t.campaign_id JOIN products pr ON pr.id = c.product_id JOIN suppliers s ON s.id = pr.supplier_id WHERE s.name = ?', [self::SUPPLIER]);
            $db->executeStatement('DELETE c FROM campaigns c JOIN products pr ON pr.id = c.product_id JOIN suppliers s ON s.id = pr.supplier_id WHERE s.name = ?', [self::SUPPLIER]);
            $db->executeStatement('DELETE pr FROM products pr JOIN suppliers s ON s.id = pr.supplier_id WHERE s.name = ?', [self::SUPPLIER]);
            $db->executeStatement('DELETE FROM suppliers WHERE name = ?', [self::SUPPLIER]);
            $db->executeStatement('DELETE FROM pickup_points WHERE name = ?', [self::POINT]);
            $db->executeStatement('DELETE FROM users WHERE email LIKE ?', [$mail]);
        } finally {
            $db->executeStatement('SET FOREIGN_KEY_CHECKS=1');
        }
    }
}
