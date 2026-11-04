<?php

namespace App\Payment\Webhook;

/** La firma del webhook non è valida: la richiesta non arriva da Stripe (o è troppo vecchia). */
final class InvalidStripeSignatureException extends \RuntimeException
{
}
