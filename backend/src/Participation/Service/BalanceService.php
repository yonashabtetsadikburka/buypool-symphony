<?php

namespace App\Participation\Service;

use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Repository\DeliveryRepository;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Payment\Gateway\CheckoutRequest;
use App\Payment\Gateway\PaymentIntentData;
use App\Payment\Gateway\StripeGateway;
use App\Payment\Gateway\StripeGatewayException;
use App\Payment\Repository\PaymentRepository;
use App\Payment\Service\StripeCustomerProvider;
use App\Pricing\BalanceBreakdown;
use App\Pricing\PricingCalculator;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Il saldo di un'adesione (US-07): calcolo sul server, pagamento da parte del cliente e conferma da Stripe.
 * Legacy: saldo_prenotazione, pagamento_checkout, saldo_completa, webhook_saldo_guardia.
 *
 * saldo = quantità × prezzo finale + commissione % sulla merce + spedizione (solo a domicilio) − acconto versato.
 * L'addebito automatico alla chiusura del gruppo è la US-11.
 */
final class BalanceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly ParticipationRepository $participations,
        private readonly DeliveryRepository $deliveries,
        private readonly PaymentRepository $payments,
        private readonly PricingCalculator $pricing,
        private readonly StripeGateway $stripe,
        private readonly StripeCustomerProvider $customers,
        private readonly LoggerInterface $logger,
        #[Autowire(env: 'FRONTEND_URL')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * Voci del saldo, tutte in centesimi e già arrotondate. Prezzo = quello fissato alla chiusura se c'è,
     * altrimenti lo scaglione raggiunto; spedizione = quella salvata con la consegna scelta.
     */
    public function breakdown(Participation $participation): BalanceBreakdown
    {
        $campaign = $participation->getCampaign();
        $price = $this->pricing->balancePrice(
            $campaign->getFinalPriceCents(),
            $campaign->getPriceTiers(),
            $campaign->getCurrentQuantity(),
            $campaign->getCurrentPriceCents(),
        );
        $delivery = $this->deliveries->findOneBy(['participation' => $participation]);
        $shipping = DeliveryMode::ConsegnaDomicilio === $delivery?->getMode() ? $delivery->getDeliveryCostCents() : 0;

        return $this->pricing->balance($participation->getQuantity(), $price, $campaign->getCommissionRate(), $shipping, $participation->getDepositCents());
    }

    /**
     * Il cliente paga il saldo con Stripe Checkout (legacy: POST /pagamento/checkout).
     *
     * @param array<string, mixed> $data { prenotazione_id }
     *
     * @return array{checkout_url: string, session_id: string, importo_cents: int}
     */
    public function createCheckout(User $user, array $data): array
    {
        $id = $data['prenotazione_id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new ApiError('DATI_NON_VALIDI', 'Indica l\'adesione (prenotazione_id)', 400, ['prenotazione_id' => 'Adesione non valida']);
        }
        $participation = $this->participations->findOneBy(['id' => Uuid::fromString($id), 'user' => $user]);
        if (null === $participation) {
            throw new ApiError('PRENOTAZIONE_NON_TROVATA', 'Adesione non trovata', 404);
        }
        if (ParticipationStatus::Confermata !== $participation->getStatus()) {
            throw new ApiError('STATO_NON_VALIDO', "Pagamento disponibile solo dopo la conferma dell'ordine. Stato attuale: ".$participation->getStatus()->value);
        }

        $breakdown = $this->breakdown($participation);
        if ($breakdown->balanceCents <= 0) {
            throw new ApiError('NESSUN_SALDO', 'Nessun saldo da pagare per questa adesione', 409);
        }
        // Stripe non accetta addebiti sotto 0,50 €.
        $amount = max(PricingCalculator::MINIMUM_DEPOSIT_CENTS, $breakdown->balanceCents);

        $campaign = $participation->getCampaign();
        $participationId = $participation->getId()->toRfc4122();
        $metadata = [
            'tipo' => 'saldo',
            'prenotazione_id' => $participationId,
            'colletta_id' => $campaign->getId()->toRfc4122(),
            'utente_id' => $user->getId()->toRfc4122(),
        ];
        $groupUrl = rtrim($this->frontendUrl, '/').'/campaigns/'.$campaign->getId()->toRfc4122();

        try {
            $session = $this->stripe->createBalanceCheckout(new CheckoutRequest(
                customerId: $this->customers->customerIdFor($user),
                productName: \sprintf('Saldo %s — %d unità', $campaign->getProduct()->getName(), $participation->getQuantity()),
                description: \sprintf(
                    'Merce € %s + commissione di servizio € %s%s − acconto € %s',
                    self::euro($breakdown->goodsCents),
                    self::euro($breakdown->commissionCents),
                    $breakdown->shippingCents > 0 ? ' + spedizione € '.self::euro($breakdown->shippingCents) : '',
                    self::euro($breakdown->depositCents),
                ),
                amountCents: $amount,
                successUrl: $groupUrl.'?pagamento=in_verifica&session_id={CHECKOUT_SESSION_ID}',
                cancelUrl: $groupUrl,
                metadata: $metadata,
                idempotencyKey: \sprintf('saldo_%s_%d_%d', $participationId, $amount, intdiv(time(), 1800)),
            ));
        } catch (StripeGatewayException $e) {
            throw new ApiError('STRIPE_ERRORE', 'Errore Stripe: '.$e->getMessage(), 500);
        }

        $participation->setBalanceCents($amount);
        $this->em->flush();

        return ['checkout_url' => $session->url, 'session_id' => $session->id, 'importo_cents' => $amount];
    }

    /**
     * Stripe conferma il pagamento di un saldo: l'adesione diventa "pagata". Idempotente sul PaymentIntent.
     * Un pagamento su un'adesione non più valida (rimborsata, annullata) o già pagata con un altro PaymentIntent
     * viene rimborsato: mai un addebito senza servizio. Se il rimborso fallisce l'errore sale e l'evento si ripete.
     */
    public function complete(PaymentIntentData $paid): PaymentOutcome
    {
        return $this->transaction->run(fn (): PaymentOutcome => $this->doComplete($paid));
    }

    private function doComplete(PaymentIntentData $paid): PaymentOutcome
    {
        $existing = $this->payments->findOneBy(['stripePaymentIntentId' => $paid->id]);
        // Un PaymentIntent prima "fallito" e poi riuscito (es. dopo 3D Secure) aggiorna la sua riga invece di duplicarla.
        if (null !== $existing && PaymentStatus::Fallito !== $existing->getStatus()) {
            return PaymentOutcome::AlreadyHandled;
        }

        $participationId = $paid->metadata['prenotazione_id'] ?? '';
        if (!Uuid::isValid($participationId)) {
            $this->logger->warning('Saldo senza prenotazione_id valido', ['payment_intent' => $paid->id]);

            return PaymentOutcome::Ignored;
        }
        $participation = $this->em->find(Participation::class, Uuid::fromString($participationId), LockMode::PESSIMISTIC_WRITE);

        if (null === $participation || ($paid->metadata['utente_id'] ?? '') !== $participation->getUser()->getId()->toRfc4122()) {
            // Adesione inesistente o di un altro utente: non c'è dove registrarlo, si rimborsa.
            $this->refund($paid);
            $this->logger->info('Saldo su adesione inesistente rimborsato', ['payment_intent' => $paid->id]);

            return PaymentOutcome::Refunded;
        }

        $status = $participation->getStatus();
        if (\in_array($status, [ParticipationStatus::Rimborsata, ParticipationStatus::Annullata, ParticipationStatus::Pagata], true)) {
            $this->refund($paid);
            $this->em->persist($this->paymentRow($participation, $paid, PaymentStatus::Rimborsato, 'rimborso_duplicato:'.(ParticipationStatus::Pagata === $status ? 'duplicato' : 'non_attivo')));
            $this->logger->info('Saldo in più rimborsato', ['payment_intent' => $paid->id, 'stato_adesione' => $status->value]);

            return PaymentOutcome::Refunded;
        }

        $commission = $this->breakdown($participation)->commissionCents;
        if (null === $existing) {
            $this->em->persist($this->paymentRow($participation, $paid, PaymentStatus::Confermato, $paid->status));
        } else {
            $existing->setAmountCents($paid->amountCents)
                ->setAgencyCommissionCents($commission)
                ->setStripeStatus(mb_substr($paid->status, 0, 50))
                ->setStatus(PaymentStatus::Confermato)
                ->setConfirmedAt(new \DateTimeImmutable());
        }
        // L'importo registrato è quello realmente incassato; il QR di ritiro nascerà con "Merce arrivata" (US-08).
        $participation->setStatus(ParticipationStatus::Pagata)
            ->setPaidAt(new \DateTimeImmutable())
            ->setBalanceCents($paid->amountCents)
            ->setCommissionCents($commission);

        return PaymentOutcome::Completed;
    }

    private function refund(PaymentIntentData $paid): void
    {
        $this->stripe->refundPaymentIntent($paid->id, 'rimborso_duplicato_'.$paid->id);
    }

    private function paymentRow(Participation $participation, PaymentIntentData $paid, PaymentStatus $status, string $stripeStatus): Payment
    {
        $commission = PaymentStatus::Confermato === $status ? $this->breakdown($participation)->commissionCents : 0;

        return (new Payment($participation, PaymentType::Saldo, $paid->amountCents))
            ->setAgencyCommissionCents($commission)
            ->setStripePaymentIntentId($paid->id)
            ->setStripePaymentMethod('' !== $paid->paymentMethod ? $paid->paymentMethod : null)
            ->setStripeStatus(mb_substr($stripeStatus, 0, 50))
            ->setStatus($status)
            ->setConfirmedAt(new \DateTimeImmutable());
    }

    private static function euro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
