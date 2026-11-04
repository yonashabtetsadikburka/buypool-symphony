<?php

namespace App\Tests\Support;

use App\Payment\Gateway\CheckoutRequest;
use App\Payment\Gateway\CheckoutSession;
use App\Payment\Gateway\CheckoutSessionStatus;
use App\Payment\Gateway\OffSessionCharge;
use App\Payment\Gateway\PaymentIntentData;
use App\Payment\Gateway\StripeGateway;
use App\Payment\Gateway\StripeGatewayException;

/** Stripe finto per i test: nessuna chiamata di rete, tutto è registrato per i controlli. */
final class FakeStripeGateway implements StripeGateway
{
    /** @var list<string> */
    public array $customers = [];
    /** @var array<string, CheckoutSession> sessioni aperte per id */
    public array $openSessions = [];
    /** @var list<string> */
    public array $expired = [];
    /** @var list<CheckoutRequest> */
    public array $checkoutRequests = [];
    /** @var array<string, string> chiave di idempotenza → id del pagamento rimborsato */
    public array $refunds = [];
    /** @var array<string, CheckoutSessionStatus> cosa "risponde Stripe" quando si rilegge una sessione */
    public array $sessionStatuses = [];
    /** @var array<string, PaymentIntentData> cosa "risponde Stripe" quando si rilegge un PaymentIntent */
    public array $paymentIntents = [];
    /** @var list<OffSessionCharge> addebiti del saldo tentati */
    public array $charges = [];
    /** @var array<string, string> id adesione → codice d'errore con cui la banca rifiuta l'addebito */
    public array $chargeFailures = [];
    /** @var array<string, string> id adesione → stato del PaymentIntent restituito (default "succeeded") */
    public array $chargeStatuses = [];
    /** @var array<string, PaymentIntentData> "adesione|importo" → addebito già esistente su Stripe */
    public array $existingBalanceIntents = [];
    /** @var list<string> PaymentIntent il cui rimborso fallisce */
    public array $failRefundOf = [];
    public bool $failRefunds = false;
    public bool $failCheckout = false;
    private int $sequence = 0;

    public function createCustomer(string $email, string $userId): string
    {
        $this->customers[] = $email;

        return 'cus_fake_'.++$this->sequence;
    }

    public function openCheckoutSessions(string $customerId): array
    {
        return array_values($this->openSessions);
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        $this->expired[] = $sessionId;
        unset($this->openSessions[$sessionId]);
    }

    public function createDepositCheckout(CheckoutRequest $request): CheckoutSession
    {
        if ($this->failCheckout) {
            throw new StripeGatewayException('Carta rifiutata.');
        }
        $this->checkoutRequests[] = $request;
        $id = 'cs_fake_'.++$this->sequence;
        $session = new CheckoutSession($id, 'https://checkout.stripe.test/'.$id, $request->amountCents, $request->metadata);
        $this->openSessions[$id] = $session;

        return $session;
    }

    public function createBalanceCheckout(CheckoutRequest $request): CheckoutSession
    {
        return $this->createDepositCheckout($request);
    }

    public function chargeBalanceOffSession(OffSessionCharge $charge): PaymentIntentData
    {
        $this->charges[] = $charge;
        $participationId = $charge->metadata['prenotazione_id'] ?? '';
        if (isset($this->chargeFailures[$participationId])) {
            throw new StripeGatewayException('Carta rifiutata.', stripeCode: $this->chargeFailures[$participationId], paymentIntentId: 'pi_failed_'.++$this->sequence);
        }

        return new PaymentIntentData('pi_charge_'.++$this->sequence, $charge->amountCents, $charge->paymentMethodId, $this->chargeStatuses[$participationId] ?? 'succeeded', $charge->metadata);
    }

    public function findBalanceIntent(string $customerId, string $participationId, int $amountCents): ?PaymentIntentData
    {
        return $this->existingBalanceIntents[$participationId.'|'.$amountCents] ?? null;
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionStatus
    {
        return $this->sessionStatuses[$sessionId] ?? throw new StripeGatewayException('Sessione sconosciuta.');
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentData
    {
        return $this->paymentIntents[$paymentIntentId] ?? throw new StripeGatewayException('PaymentIntent sconosciuto.');
    }

    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): void
    {
        if ($this->failRefunds || \in_array($paymentIntentId, $this->failRefundOf, true)) {
            throw new StripeGatewayException('Rimborso non riuscito.');
        }
        $this->refunds[$idempotencyKey] = $paymentIntentId;
    }
}
