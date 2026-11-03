<?php

namespace App\Payment\Gateway;

/**
 * Confine con Stripe. Il resto dell'app non conosce l'API di Stripe: nei test si usa un finto gateway,
 * in produzione {@see HttpStripeGateway}. Le chiavi di idempotenza evitano doppi addebiti e doppi rimborsi.
 */
interface StripeGateway
{
    /** Crea il Customer Stripe dell'utente (serve per gli addebiti futuri senza il cliente). */
    public function createCustomer(string $email, string $userId): string;

    /**
     * Sessioni di pagamento ancora aperte del cliente.
     *
     * @return list<CheckoutSession>
     */
    public function openCheckoutSessions(string $customerId): array;

    public function expireCheckoutSession(string $sessionId): void;

    public function createDepositCheckout(DepositCheckoutRequest $request): CheckoutSession;

    /** Rimborso totale di un pagamento. Un pagamento già rimborsato non è un errore. */
    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): void;
}
