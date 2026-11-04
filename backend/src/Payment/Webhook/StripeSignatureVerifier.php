<?php

namespace App\Payment\Webhook;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Verifica la firma di un webhook Stripe (header "Stripe-Signature: t=<tempo>,v1=<firma>[,v1=…]"):
 * firma = HMAC-SHA256 di "<tempo>.<corpo>" con il segreto del webhook. Come la libreria ufficiale rifiuta
 * le richieste più vecchie di 5 minuti (replay). Senza segreto configurato rifiuta tutto.
 */
final class StripeSignatureVerifier
{
    public function __construct(
        #[Autowire(env: 'STRIPE_WEBHOOK_SECRET')]
        private readonly string $secret,
        private readonly int $toleranceSeconds = 300,
    ) {
    }

    /**
     * @throws InvalidStripeSignatureException
     */
    public function verify(string $payload, string $header, ?int $now = null): void
    {
        if ('' === $this->secret) {
            throw new InvalidStripeSignatureException('Webhook non configurato (manca STRIPE_WEBHOOK_SECRET).');
        }

        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ('t' === $key && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ('v1' === $key && '' !== $value) {
                $signatures[] = $value;
            }
        }
        if (null === $timestamp || [] === $signatures) {
            throw new InvalidStripeSignatureException('Intestazione della firma non valida.');
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->secret);
        $match = false;
        foreach ($signatures as $signature) {
            // Confronto a tempo costante, su tutte le firme presenti.
            $match = hash_equals($expected, $signature) || $match;
        }
        if (!$match) {
            throw new InvalidStripeSignatureException('Firma non corrispondente.');
        }
        if (abs(($now ?? time()) - $timestamp) > $this->toleranceSeconds) {
            throw new InvalidStripeSignatureException('Firma troppo vecchia.');
        }
    }
}
