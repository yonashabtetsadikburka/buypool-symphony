<?php

namespace App\Payment\Gateway;

/** Un PaymentIntent di Stripe, ridotto ai dati che servono a BuyPool (dal webhook o da una lettura diretta). */
final class PaymentIntentData
{
    /**
     * @param array<string, string> $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly int $amountCents,
        public readonly string $paymentMethod,
        public readonly string $status,
        public readonly array $metadata,
    ) {
    }

    /**
     * Da un oggetto PaymentIntent di Stripe (JSON decodificato). Importo = quello realmente incassato.
     *
     * @param array<mixed> $object
     */
    public static function fromStripeObject(array $object): self
    {
        $id = $object['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new StripeGatewayException('PaymentIntent senza identificativo.');
        }
        $amount = $object['amount_received'] ?? $object['amount'] ?? 0;
        $method = $object['payment_method'] ?? '';
        $metadata = [];
        foreach (\is_array($object['metadata'] ?? null) ? $object['metadata'] : [] as $key => $value) {
            if (\is_string($key) && \is_scalar($value)) {
                $metadata[$key] = (string) $value;
            }
        }

        return new self($id, \is_int($amount) ? $amount : 0, \is_string($method) ? $method : '', \is_string($object['status'] ?? null) ? $object['status'] : 'succeeded', $metadata);
    }
}
