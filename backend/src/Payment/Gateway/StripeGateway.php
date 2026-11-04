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

    /** Pagamento dell'acconto: autorizza anche l'addebito futuro del saldo senza il cliente. */
    public function createDepositCheckout(CheckoutRequest $request): CheckoutSession;

    /** Pagamento del saldo da parte del cliente. */
    public function createBalanceCheckout(CheckoutRequest $request): CheckoutSession;

    /**
     * Addebita il saldo con la carta salvata, senza il cliente. Se la banca rifiuta o chiede una nuova
     * autorizzazione lancia {@see StripeGatewayException} con codice e (se c'è) PaymentIntent del tentativo.
     */
    public function chargeBalanceOffSession(OffSessionCharge $charge): PaymentIntentData;

    /**
     * Addebito di saldo già fatto per questa adesione e questo importo (riuscito o ancora in corso), se esiste.
     * Evita il doppio addebito quando un giro precedente ha addebitato ma non ha finito di salvare.
     */
    public function findBalanceIntent(string $customerId, string $participationId, int $amountCents): ?PaymentIntentData;

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionStatus;

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentData;

    /** Rimborso totale di un pagamento. Un pagamento già rimborsato non è un errore. */
    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): void;
}
