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

    public function createDepositCheckout(CheckoutRequest $request): CheckoutSession
    {
        // L'acconto autorizza anche il saldo futuro senza il cliente.
        return $this->createCheckout($request, ['setup_future_usage' => 'off_session']);
    }

    public function createBalanceCheckout(CheckoutRequest $request): CheckoutSession
    {
        return $this->createCheckout($request, []);
    }

    public function chargeBalanceOffSession(OffSessionCharge $charge): PaymentIntentData
    {
        return PaymentIntentData::fromStripeObject($this->call('POST', '/payment_intents', [
            'amount' => $charge->amountCents,
            'currency' => 'eur',
            'customer' => $charge->customerId,
            'payment_method' => $charge->paymentMethodId,
            'off_session' => 'true',
            'confirm' => 'true',
            // Niente metodi con redirect: un addebito senza il cliente non può reindirizzare.
            'automatic_payment_methods' => ['enabled' => 'true', 'allow_redirects' => 'never'],
            'metadata' => $charge->metadata,
        ], $charge->idempotencyKey));
    }

    public function findBalanceIntent(string $customerId, string $participationId, int $amountCents): ?PaymentIntentData
    {
        $data = $this->call('GET', '/payment_intents', ['customer' => $customerId, 'limit' => 50]);

        $inProgress = null;
        foreach (\is_array($data['data'] ?? null) ? $data['data'] : [] as $object) {
            if (!\is_array($object)) {
                continue;
            }
            $intent = PaymentIntentData::fromStripeObject($object);
            $requested = (int) ($object['amount'] ?? 0);
            if (($intent->metadata['tipo'] ?? null) !== 'saldo' || ($intent->metadata['prenotazione_id'] ?? null) !== $participationId || $requested !== $amountCents) {
                continue;
            }
            if ('succeeded' === $intent->status) {
                return $intent;
            }
            if (\in_array($intent->status, ['processing', 'requires_capture'], true)) {
                $inProgress = $intent;
            }
        }

        return $inProgress;
    }

    public function retrieveCheckoutSession(string $sessionId): CheckoutSessionStatus
    {
        $data = $this->call('GET', '/checkout/sessions/'.rawurlencode($sessionId));
        $intent = $data['payment_intent'] ?? '';
        if (\is_array($intent)) {
            $intent = $intent['id'] ?? '';
        }

        return new CheckoutSessionStatus(
            self::string($data['id'] ?? null),
            \is_string($data['payment_status'] ?? null) ? $data['payment_status'] : 'unpaid',
            \is_string($intent) ? $intent : '',
            (int) ($data['amount_total'] ?? 0),
            self::metadata($data['metadata'] ?? null),
        );
    }

    public function retrievePaymentIntent(string $paymentIntentId): PaymentIntentData
    {
        return PaymentIntentData::fromStripeObject($this->call('GET', '/payment_intents/'.rawurlencode($paymentIntentId)));
    }

    /**
     * @param array<string, string> $intentOptions opzioni extra del PaymentIntent
     */
    private function createCheckout(CheckoutRequest $request, array $intentOptions): CheckoutSession
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
            // I metadati stanno anche sul PaymentIntent: è quello che arriva al webhook.
            'payment_intent_data' => $intentOptions + ['metadata' => $request->metadata],
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
            $intent = \is_array($error['payment_intent'] ?? null) ? ($error['payment_intent']['id'] ?? null) : null;
            throw new StripeGatewayException(
                \is_string($error['message'] ?? null) ? $error['message'] : 'Richiesta a Stripe rifiutata.',
                stripeCode: \is_string($error['code'] ?? null) ? $error['code'] : null,
                paymentIntentId: \is_string($intent) ? $intent : null,
            );
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
