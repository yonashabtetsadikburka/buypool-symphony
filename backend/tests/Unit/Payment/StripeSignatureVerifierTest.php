<?php

namespace App\Tests\Unit\Payment;

use App\Payment\Webhook\InvalidStripeSignatureException;
use App\Payment\Webhook\StripeSignatureVerifier;
use PHPUnit\Framework\TestCase;

final class StripeSignatureVerifierTest extends TestCase
{
    private const SECRET = 'whsec_unit_secret';
    private const NOW = 1_800_000_000;

    public function testValidSignatureIsAccepted(): void
    {
        $payload = '{"id":"evt_1"}';

        (new StripeSignatureVerifier(self::SECRET))->verify($payload, $this->header($payload, self::NOW), self::NOW);

        $this->addToAssertionCount(1);
    }

    public function testOneValidSignatureAmongSeveralIsEnough(): void
    {
        $payload = '{"id":"evt_1"}';
        $header = $this->header($payload, self::NOW).',v1='.str_repeat('0', 64);

        (new StripeSignatureVerifier(self::SECRET))->verify($payload, $header, self::NOW);

        $this->addToAssertionCount(1);
    }

    public function testChangedPayloadIsRejected(): void
    {
        $header = $this->header('{"id":"evt_1"}', self::NOW);

        $this->expectException(InvalidStripeSignatureException::class);
        (new StripeSignatureVerifier(self::SECRET))->verify('{"id":"evt_2"}', $header, self::NOW);
    }

    public function testSignatureMadeWithAnotherSecretIsRejected(): void
    {
        $payload = '{"id":"evt_1"}';
        $header = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$payload, 'altro_segreto');

        $this->expectException(InvalidStripeSignatureException::class);
        (new StripeSignatureVerifier(self::SECRET))->verify($payload, $header, self::NOW);
    }

    public function testOldSignatureIsRejectedAsReplay(): void
    {
        $payload = '{"id":"evt_1"}';

        $this->expectException(InvalidStripeSignatureException::class);
        $this->expectExceptionMessage('vecchia');
        (new StripeSignatureVerifier(self::SECRET))->verify($payload, $this->header($payload, self::NOW - 301), self::NOW);
    }

    public function testSignatureAtTheToleranceLimitIsAccepted(): void
    {
        $payload = '{"id":"evt_1"}';

        (new StripeSignatureVerifier(self::SECRET))->verify($payload, $this->header($payload, self::NOW - 300), self::NOW);

        $this->addToAssertionCount(1);
    }

    /**
     * @dataProvider malformedHeaders
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('malformedHeaders')]
    public function testMalformedHeaderIsRejected(string $header): void
    {
        $this->expectException(InvalidStripeSignatureException::class);
        (new StripeSignatureVerifier(self::SECRET))->verify('{}', $header, self::NOW);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedHeaders(): iterable
    {
        yield 'vuoto' => [''];
        yield 'senza tempo' => ['v1=abc'];
        yield 'senza firma' => ['t=1800000000'];
        yield 'tempo non numerico' => ['t=adesso,v1=abc'];
        yield 'firma vuota' => ['t=1800000000,v1='];
    }

    public function testWithoutConfiguredSecretEverythingIsRejected(): void
    {
        $payload = '{}';
        // Anche una firma "valida" calcolata con il segreto vuoto non passa.
        $header = 't='.self::NOW.',v1='.hash_hmac('sha256', self::NOW.'.'.$payload, '');

        $this->expectException(InvalidStripeSignatureException::class);
        $this->expectExceptionMessage('STRIPE_WEBHOOK_SECRET');
        (new StripeSignatureVerifier(''))->verify($payload, $header, self::NOW);
    }

    private function header(string $payload, int $timestamp): string
    {
        return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, self::SECRET);
    }
}
