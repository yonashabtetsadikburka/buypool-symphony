<?php

namespace App\Participation\Service;

use App\Campaign\Application\CampaignTransitioner;
use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Enum\DeliveryMode;
use App\Identity\Entity\User;
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
 * Le notifiche e le email al cliente e al fornitore arriveranno con le notifiche (US-09, US-23).
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
    ) {
    }

    /**
     * Se il rimborso di un pagamento in più non riesce lancia l'eccezione del gateway: l'evento non va chiuso,
     * Stripe lo ripete e il pagamento non si perde.
     */
    public function confirm(PaidDeposit $paid): DepositOutcome
    {
        return $this->transaction->run(fn (): DepositOutcome => $this->doConfirm($paid));
    }

    private function doConfirm(PaidDeposit $paid): DepositOutcome
    {
        if (null !== $this->payments->findOneBy(['stripePaymentIntentId' => $paid->paymentIntentId])) {
            return DepositOutcome::AlreadyHandled;
        }

        $meta = $paid->metadata;
        $mode = DeliveryMode::tryFrom($meta['delivery_type'] ?? '');
        $campaignId = $meta['colletta_id'] ?? '';
        $userId = $meta['utente_id'] ?? '';
        if (null === $mode || !Uuid::isValid($campaignId) || !Uuid::isValid($userId)) {
            $this->logger->warning('Acconto senza metadati validi', ['payment_intent' => $paid->paymentIntentId]);

            return DepositOutcome::Ignored;
        }

        // Il gruppo resta bloccato fino alla fine: due acconti contemporanei non si pestano i piedi.
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($campaignId), LockMode::PESSIMISTIC_WRITE);
        $user = $this->em->find(User::class, Uuid::fromString($userId));
        if (!$campaign instanceof Campaign || !$user instanceof User) {
            $this->logger->warning('Acconto per gruppo o utente inesistente', ['payment_intent' => $paid->paymentIntentId]);

            return DepositOutcome::Ignored;
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

            return DepositOutcome::Refunded;
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

        $campaign->setCurrentQuantity($campaign->getCurrentQuantity() + $quantity);
        if (CampaignStatus::InCorso === $campaign->getStatus() && $campaign->getCurrentQuantity() >= $campaign->getMoq()) {
            $this->transitioner->apply($campaign, CampaignTransition::ReachMoq);
            $this->confirmReservedParticipations($campaign, $participation);
        }

        return DepositOutcome::Created;
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
