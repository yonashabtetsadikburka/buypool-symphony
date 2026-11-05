<?php

namespace App\Participation\Service;

use App\Campaign\Application\CampaignTransitioner;
use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Enum\DeliveryMode;
use App\Campaign\Entity\PriceTier;
use App\Identity\Entity\User;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Notification\Service\NotificationService;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Payment\Gateway\StripeGateway;
use App\Payment\Repository\PaymentRepository;
use App\Pricing\PricingCalculator;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Trasforma un acconto pagato in adesione (legacy: webhook_acconto_crea). Il chiamante è il webhook di Stripe
 * (US-07); qui non c'è HTTP. Idempotente sul PaymentIntent: lo stesso pagamento visto due volte non crea due adesioni.
 *
 * Notifiche in-app (US-25, come il legacy): all'aderente "acconto addebitato"; a tutti gli aderenti e al fornitore "minimo
 * raggiunto" quando il gruppo lo raggiunge adesso; a tutti gli aderenti "il prezzo è sceso" quando scatta un nuovo scaglione
 * (dal secondo in poi). Scattano una volta sola perché il passaggio di soglia si vede sotto il blocco del gruppo.
 * Le email arriveranno con la US-23.
 */
final class DepositConfirmationService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly ParticipationRepository $participations,
        private readonly PaymentRepository $payments,
        private readonly PricingCalculator $pricing,
        private readonly CampaignTransitioner $transitioner,
        private readonly StripeGateway $stripe,
        private readonly LoggerInterface $logger,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * Se il rimborso di un pagamento in più non riesce lancia l'eccezione del gateway: l'evento non va chiuso,
     * Stripe lo ripete e il pagamento non si perde.
     */
    public function confirm(PaidDeposit $paid): PaymentOutcome
    {
        return $this->transaction->run(fn (): PaymentOutcome => $this->doConfirm($paid));
    }

    private function doConfirm(PaidDeposit $paid): PaymentOutcome
    {
        if (null !== $this->payments->findOneBy(['stripePaymentIntentId' => $paid->paymentIntentId])) {
            return PaymentOutcome::AlreadyHandled;
        }

        $meta = $paid->metadata;
        $mode = DeliveryMode::tryFrom($meta['delivery_type'] ?? '');
        $campaignId = $meta['colletta_id'] ?? '';
        $userId = $meta['utente_id'] ?? '';
        if (null === $mode || !Uuid::isValid($campaignId) || !Uuid::isValid($userId)) {
            $this->logger->warning('Acconto senza metadati validi', ['payment_intent' => $paid->paymentIntentId]);

            return PaymentOutcome::Ignored;
        }

        // Il gruppo resta bloccato fino alla fine: due acconti contemporanei non si pestano i piedi.
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($campaignId), LockMode::PESSIMISTIC_WRITE);
        $user = $this->em->find(User::class, Uuid::fromString($userId));
        if (!$campaign instanceof Campaign || !$user instanceof User) {
            $this->logger->warning('Acconto per gruppo o utente inesistente', ['payment_intent' => $paid->paymentIntentId]);

            return PaymentOutcome::Ignored;
        }

        $quantity = max(1, (int) ($meta['quantita'] ?? 1));
        $existing = $this->participations->findOneBy(['campaign' => $campaign, 'user' => $user]);
        $open = \in_array($campaign->getStatus(), [CampaignStatus::InCorso, CampaignStatus::Riuscita], true)
            && $campaign->getDeadline() > new \DateTimeImmutable();

        if (null !== $existing || !$open) {
            // Pagamento in più o in ritardo: rimborso intero, nessuna adesione riattivata.
            // Duplicato = adesione ancora valida con un altro pagamento; altrimenti l'adesione non è più attiva.
            $duplicate = null !== $existing && !\in_array($existing->getStatus(), [ParticipationStatus::Rimborsata, ParticipationStatus::Annullata], true);
            $this->stripe->refundPaymentIntent($paid->paymentIntentId, 'rimborso_duplicato_'.$paid->paymentIntentId);
            if (null !== $existing) {
                // La riga del pagamento (PaymentIntent unico) segnala anche "già gestito" agli eventi successivi.
                $this->em->persist($this->paymentRow($existing, $paid, PaymentStatus::Rimborsato, 'rimborso_duplicato:'.($duplicate ? 'duplicato' : 'non_attivo')));
            }
            $this->logger->info('Acconto in più rimborsato', ['payment_intent' => $paid->paymentIntentId, 'motivo' => $duplicate ? 'duplicato' : 'non_attivo']);

            return PaymentOutcome::Refunded;
        }

        // Se il gruppo ha già raggiunto il minimo l'adesione nasce "confermata": la chiusura addebita solo le confermate.
        $participation = new Participation($campaign, $user, $quantity);
        $participation->setDepositCents($paid->amountCents)
            ->setStatus(CampaignStatus::Riuscita === $campaign->getStatus() ? ParticipationStatus::Confermata : ParticipationStatus::Prenotata);
        $this->em->persist($participation);

        $delivery = new Delivery($participation, $mode);
        $delivery->setDeliveryCostCents($this->pricing->shipping($mode));
        if (DeliveryMode::RitiroSede === $mode) {
            $delivery->setPickupPoint($this->pickupPoint($meta['id_sede'] ?? ''));
        } elseif ('' !== trim($meta['indirizzo'] ?? '')) {
            $delivery->setDeliveryAddress(trim($meta['indirizzo']));
        }
        $this->em->persist($delivery);

        $this->em->persist($this->paymentRow($participation, $paid, PaymentStatus::Confermato, $paid->stripeStatus));

        $before = $campaign->getCurrentQuantity();
        $campaign->setCurrentQuantity($before + $quantity);
        $reachedMinimum = false;
        if (CampaignStatus::InCorso === $campaign->getStatus() && $campaign->getCurrentQuantity() >= $campaign->getMoq()) {
            $this->transitioner->apply($campaign, CampaignTransition::ReachMoq);
            $this->confirmReservedParticipations($campaign, $participation);
            $reachedMinimum = true;
        }

        $this->notifyDeposit($campaign, $participation, $mode, $before, $reachedMinimum);

        return PaymentOutcome::Created;
    }

    /**
     * Acconto addebitato al cliente; se il gruppo ha appena raggiunto il minimo, avviso a tutti gli aderenti e al fornitore;
     * se è scattato un nuovo scaglione (dal secondo in poi), "il prezzo è sceso" a tutti gli aderenti.
     */
    private function notifyDeposit(Campaign $campaign, Participation $participation, DeliveryMode $mode, int $before, bool $reachedMinimum): void
    {
        $tiers = $this->sortedTiers($campaign);
        $name = $campaign->getProduct()->getName();
        $campaignId = $campaign->getId()->toRfc4122();
        $quantity = $participation->getQuantity();
        $balance = $this->pricing->balance(
            $quantity,
            $this->pricing->estimatePrice($tiers, $campaign->getCurrentQuantity(), $campaign->getCurrentPriceCents()),
            $campaign->getCommissionRate(),
            $this->pricing->shipping($mode),
            $participation->getDepositCents(),
        );
        $this->notifications->notify(
            $participation->getUser(),
            NotificationType::OrdineConfermato,
            'Acconto addebitato',
            \sprintf(
                '%s per %s (%d unità). Il saldo di %s viene addebitato solo se il gruppo raggiunge il minimo.',
                self::euro($participation->getDepositCents()),
                $name,
                $quantity,
                self::euro(max(0, $balance->balanceCents)),
            ),
            ReferenceType::Colletta,
            $campaignId,
        );

        $priorTier = $this->tierIndex($tiers, $before);
        $newTier = $this->tierIndex($tiers, $campaign->getCurrentQuantity());
        $newPrice = $newTier >= 1 && $newTier > $priorTier;
        if (!$reachedMinimum && !$newPrice) {
            return;
        }

        // Aderenti vivi del gruppo (la nuova adesione è già nel database dopo il flush).
        $this->em->flush();
        $members = $this->participations->findByCampaignAndStatuses($campaign, [ParticipationStatus::Prenotata, ParticipationStatus::Confermata, ParticipationStatus::Pagata, ParticipationStatus::AzioneRichiesta]);

        if ($reachedMinimum) {
            $until = $campaign->getDeadline()->format('d/m/Y');
            foreach ($members as $member) {
                $this->notifications->notify(
                    $member->getUser(),
                    NotificationType::MoqRaggiunto,
                    'Minimo raggiunto',
                    \sprintf('Il gruppo ha raggiunto il minimo per "%s"! Il gruppo resta aperto fino al %s: più persone aderiscono, più il prezzo può ancora scendere.', $name, $until),
                    ReferenceType::Colletta,
                    $campaignId,
                );
            }
            $supplierUser = $campaign->getProduct()->getSupplier()->getUser();
            if (null !== $supplierUser) {
                $this->notifications->notify(
                    $supplierUser,
                    NotificationType::MoqRaggiunto,
                    'Minimo raggiunto',
                    \sprintf('Soglia MOQ raggiunta per "%s"! Preparati a evadere l\'ordine cumulativo.', $name),
                    ReferenceType::Colletta,
                    $campaignId,
                );
            }
        }

        if ($newPrice) {
            $message = \sprintf('%s ora costa %s: il gruppo ha raggiunto %d unità.', $name, self::euro($tiers[$newTier]->getPriceCents()), $campaign->getCurrentQuantity());
            foreach ($members as $member) {
                $this->notifications->notify($member->getUser(), NotificationType::NuovoScaglione, 'Il prezzo è sceso', $message, ReferenceType::Colletta, $campaignId);
            }
        }
    }

    /**
     * @return list<PriceTier>
     */
    private function sortedTiers(Campaign $campaign): array
    {
        $tiers = $campaign->getPriceTiers()->toArray();
        usort($tiers, static fn (PriceTier $a, PriceTier $b): int => $a->getThreshold() <=> $b->getThreshold());

        return $tiers;
    }

    /**
     * Indice dello scaglione più alto raggiunto da $quantity unità (-1 se nessuno).
     *
     * @param list<PriceTier> $tiers
     */
    private function tierIndex(array $tiers, int $quantity): int
    {
        $index = -1;
        foreach ($tiers as $position => $tier) {
            if ($quantity >= $tier->getThreshold()) {
                $index = $position;
            }
        }

        return $index;
    }

    private static function euro(int $cents): string
    {
        return '€'.number_format($cents / 100, 2, ',', '.');
    }

    /** Il gruppo ha appena raggiunto il minimo: le adesioni "prenotata" diventano "confermata". */
    private function confirmReservedParticipations(Campaign $campaign, Participation $current): void
    {
        $current->setStatus(ParticipationStatus::Confermata);
        foreach ($this->participations->findBy(['campaign' => $campaign, 'status' => ParticipationStatus::Prenotata]) as $reserved) {
            $reserved->setStatus(ParticipationStatus::Confermata);
        }
    }

    private function paymentRow(Participation $participation, PaidDeposit $paid, PaymentStatus $status, string $stripeStatus): Payment
    {
        $now = new \DateTimeImmutable();
        $payment = new Payment($participation, PaymentType::Acconto, $paid->amountCents);
        $payment->setStripePaymentIntentId($paid->paymentIntentId)
            ->setStripePaymentMethod('' !== $paid->paymentMethod ? $paid->paymentMethod : null)
            ->setStripeStatus(mb_substr($stripeStatus, 0, 50))
            ->setStatus($status)
            ->setConfirmedAt($now);

        return $payment;
    }

    private function pickupPoint(string $id): ?PickupPoint
    {
        return Uuid::isValid($id) ? $this->em->find(PickupPoint::class, Uuid::fromString($id)) : null;
    }
}
