<?php

namespace App\Payment\Controller;

use App\Payment\Webhook\InvalidStripeSignatureException;
use App\Payment\Webhook\StripeSignatureVerifier;
use App\Payment\Webhook\StripeWebhookProcessor;
use App\Shared\Http\ApiError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Webhook di Stripe (pubblico, protetto dalla firma). Senza firma valida risponde 400 e non cambia nulla.
 */
final class StripeWebhookController
{
    #[Route('/api/stripe/webhook', name: 'api_stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request, StripeSignatureVerifier $verifier, StripeWebhookProcessor $processor): JsonResponse
    {
        $signature = $request->headers->get('Stripe-Signature', '');
        if ('' === $signature) {
            throw new ApiError('FIRMA_MANCANTE', 'Missing signature');
        }

        // Il corpo va letto così com'è: la firma è calcolata sui byte originali.
        $payload = $request->getContent();
        try {
            $verifier->verify($payload, $signature);
        } catch (InvalidStripeSignatureException) {
            throw new ApiError('FIRMA_NON_VALIDA', 'Invalid signature');
        }

        $event = json_decode($payload, true);
        if (!\is_array($event) || !\is_string($event['id'] ?? null) || '' === $event['id'] || !\is_string($event['type'] ?? null)) {
            throw new ApiError('PAYLOAD_NON_VALIDO', 'Invalid payload');
        }

        $result = $processor->process($event['id'], $event['type'], $event);

        return new JsonResponse(StripeWebhookProcessor::DUPLICATE === $result ? ['received' => true, 'duplicate' => true] : ['received' => true]);
    }
}
