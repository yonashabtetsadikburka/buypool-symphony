<?php

namespace App\Payment\Gateway;

/**
 * Errore nel parlare con Stripe (rete, chiave, rifiuto). Il messaggio non contiene mai chiavi.
 * $stripeCode e $paymentIntentId, quando Stripe li dà (es. carta rifiutata), servono a registrare il tentativo
 * senza testo libero né dati personali.
 */
final class StripeGatewayException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $stripeCode = null,
        public readonly ?string $paymentIntentId = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
