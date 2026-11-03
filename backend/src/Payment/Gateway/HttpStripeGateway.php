<?php

namespace App\Payment\Gateway;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Gateway verso l'API REST di Stripe via HttpClient (nessuna libreria in più).
 * Usa la chiave segreta in STRIPE_SECRET_KEY; senza chiave ogni chiamata fallisce con un errore chiaro.
 */
final class HttpStripeGateway implements StripeGateway
{
    private const BASE_URL = 'https://api.stripe.com/v1';

    public function __construct(
        private readonly HttpClientInterface $http,
        #[Autowire(env: 'STRIPE_SECRET_KEY')]
        private readonly string $secretKey,
    ) {
    }

    public function createCustomer(string $email, string $userId): string
    {
        $data = $this->call('POST', '/customers', ['email' => $email, 'metadata' => ['utente_id' => $userId]]);

        return self::string($data['id'] ?? null);
    }

    public function openCheckoutSessions(string $customerId): array
    {
        $data = $this->call('GET', '/checkout/sessions', ['customer' => $customerId, 'status' => 'open', 'limit' => 20]);

        $sessions = [];
        foreach (\is_array($data['data'] ?? null) ? $data['data'] : [] as $item) {
            if (\is_array($item) && isset($item['id'], $item['url']) && \is_string($item['id']) && \is_string($item['url'])) {
                $sessions[] = new CheckoutSession($item['id'], $item['url'], (int) ($item['amount_total'] ?? 0), self::metadata($item['metadata'] ?? null));
            }
        }

        return $sessions;
    }

    public function expireCheckoutSession(string $sessionId): void
    {
        $this->call('POST', '/checkout/sessions/'.rawurlencode($sessionId).'/expire');
    }

    public function createDepositCheckout(DepositCheckoutRequest $request): CheckoutSession
    {
        $data = $this->call('POST', '/checkout/sessions', [
            'payment_method_types' => ['card'],
            'customer' => $request->customerId,
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'eur',
                    'product_data' => ['name' => $request->productName, 'description' => $request->description],
                    'unit_amount' => $request->amountCents,
                ],
                'quantity' => 1,
            ]],
            // L'acconto autorizza anche il saldo futuro senza il cliente.
            'payment_intent_data' => ['setup_future_usage' => 'off_session', 'metadata' => $request->metadata],
            'success_url' => $request->successUrl,
            'cancel_url' => $request->cancelUrl,
            'metadata' => $request->metadata,
        ], $request->idempotencyKey);

        return new CheckoutSession(self::string($data['id'] ?? null), self::string($data['url'] ?? null), $request->amountCents, $request->metadata);
    }

    public function refundPaymentIntent(string $paymentIntentId, string $idempotencyKey): void
    {
        try {
            $this->call('POST', '/refunds', ['payment_intent' => $paymentIntentId], $idempotencyKey);
        } catch (StripeGatewayException $e) {
            // Già rimborsato (anche da un'altra strada): è come se fosse fatto.
            if (false === stripos($e->getMessage(), 'already been refunded')) {
                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function call(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        if ('' === $this->secretKey) {
            throw new StripeGatewayException('Stripe non è configurato (manca STRIPE_SECRET_KEY).');
        }

        $options = ['auth_basic' => [$this->secretKey, ''], 'headers' => []];
        if (null !== $idempotencyKey) {
            $options['headers']['Idempotency-Key'] = $idempotencyKey;
        }
        if ('GET' === $method) {
            $options['query'] = $params;
        } elseif ([] !== $params) {
            $options['body'] = http_build_query($params);
            $options['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
        }

        try {
            $response = $this->http->request($method, self::BASE_URL.$path, $options);
            $status = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new StripeGatewayException('Stripe non raggiungibile.', 0, $e);
        }

        if ($status >= 400) {
            $error = \is_array($data['error'] ?? null) ? $data['error'] : [];
            throw new StripeGatewayException(\is_string($error['message'] ?? null) ? $error['message'] : 'Richiesta a Stripe rifiutata.');
        }

        return $data;
    }

    private static function string(mixed $value): string
    {
        if (!\is_string($value) || '' === $value) {
            throw new StripeGatewayException('Risposta di Stripe incompleta.');
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private static function metadata(mixed $raw): array
    {
        $metadata = [];
        foreach (\is_array($raw) ? $raw : [] as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $metadata[$key] = (string) $value;
            }
        }

        return $metadata;
    }
}
