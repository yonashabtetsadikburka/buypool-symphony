<?php

namespace App\Payment\Gateway;

/** Errore nel parlare con Stripe (rete, chiave, rifiuto). Il messaggio non contiene mai chiavi. */
final class StripeGatewayException extends \RuntimeException
{
}
