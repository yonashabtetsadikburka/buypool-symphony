<?php

namespace App\Tests\Support;

use App\Payment\Gateway\CheckoutSession;
use App\Payment\Gateway\DepositCheckoutRequest;
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
    /** @var list<DepositCheckoutRequest> */
    public array $checkoutRequests = [];
    /** @var array<string, string> chiave di idempotenza → id del pagamento rimborsato */
    public array $refunds = [];
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

    public function createDepositCheckout(DepositCheckoutRequest $request): CheckoutSession
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

    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): void
    {
        if ($this->failRefunds) {
            throw new StripeGatewayException('Rimborso non riuscito.');
        }
        $this->refunds[$idempotencyKey] = $paymentIntentId;
    }
}
