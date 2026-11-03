<?php

namespace App\Tests\Unit\Payment;

use App\Payment\Gateway\DepositCheckoutRequest;
use App\Payment\Gateway\HttpStripeGateway;
use App\Payment\Gateway\StripeGatewayException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HttpStripeGatewayTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $calls = [];

    public function testWithoutSecretKeyNothingIsSent(): void
    {
        $gateway = $this->gateway('', []);

        $this->expectException(StripeGatewayException::class);
        $this->expectExceptionMessage('STRIPE_SECRET_KEY');
        try {
            $gateway->createCustomer('a@b.it', 'u1');
        } finally {
            self::assertSame([], $this->calls);
        }
    }

    public function testCreateCustomerPostsFormEncodedDataWithBasicAuth(): void
    {
        $gateway = $this->gateway('sk_test_x', [new MockResponse('{"id":"cus_123"}')]);

        self::assertSame('cus_123', $gateway->createCustomer('a@b.it', 'u1'));

        self::assertSame('POST', $this->calls[0]['method']);
        self::assertSame('https://api.stripe.com/v1/customers', $this->calls[0]['url']);
        $headers = implode("\n", $this->calls[0]['options']['headers']);
        self::assertStringContainsString('Authorization: Basic '.base64_encode('sk_test_x:'), $headers);
        self::assertStringContainsString('Content-Type: application/x-www-form-urlencoded', $headers);
        parse_str((string) $this->calls[0]['options']['body'], $body);
        self::assertSame(['email' => 'a@b.it', 'metadata' => ['utente_id' => 'u1']], $body);
    }

    public function testCheckoutSessionCarriesAmountMetadataAndIdempotencyKey(): void
    {
        $gateway = $this->gateway('sk_test_x', [new MockResponse('{"id":"cs_1","url":"https://checkout.stripe.com/c/cs_1"}')]);

        $session = $gateway->createDepositCheckout(new DepositCheckoutRequest('cus_1', 'Acconto Zaino', 'Acconto gruppo g1', 540, 'https://f/ok', 'https://f/ko', ['colletta_id' => 'g1'], 'key-1'));

        self::assertSame('cs_1', $session->id);
        self::assertSame('https://checkout.stripe.com/c/cs_1', $session->url);
        self::assertSame(540, $session->amountCents);
        parse_str((string) $this->calls[0]['options']['body'], $body);
        self::assertSame('payment', $body['mode']);
        self::assertSame('eur', $body['line_items'][0]['price_data']['currency']);
        self::assertSame('540', $body['line_items'][0]['price_data']['unit_amount']);
        self::assertSame('off_session', $body['payment_intent_data']['setup_future_usage']);
        self::assertSame('g1', $body['metadata']['colletta_id']);
        self::assertStringContainsString('Idempotency-Key: key-1', implode("\n", $this->calls[0]['options']['headers']));
    }

    public function testOpenSessionsAreReadFromTheListAndIncompleteOnesSkipped(): void
    {
        $json = '{"data":[{"id":"cs_1","url":"https://c/1","amount_total":540,"metadata":{"colletta_id":"g1"}},{"id":"cs_2","url":null}]}';
        $gateway = $this->gateway('sk_test_x', [new MockResponse($json)]);

        $sessions = $gateway->openCheckoutSessions('cus_1');

        self::assertCount(1, $sessions);
        self::assertSame(540, $sessions[0]->amountCents);
        self::assertSame(['colletta_id' => 'g1'], $sessions[0]->metadata);
        self::assertSame('GET', $this->calls[0]['method']);
        self::assertStringContainsString('customer=cus_1', $this->calls[0]['url']);
        self::assertStringContainsString('status=open', $this->calls[0]['url']);
    }

    public function testAlreadyRefundedPaymentIsNotAnError(): void
    {
        $gateway = $this->gateway('sk_test_x', [new MockResponse('{"error":{"message":"Charge ch_1 has already been refunded."}}', ['http_code' => 400])]);

        $gateway->refundPaymentIntent('pi_1', 'rimborso_pi_1');

        self::assertStringContainsString('Idempotency-Key: rimborso_pi_1', implode("\n", $this->calls[0]['options']['headers']));
        $this->addToAssertionCount(1);
    }

    public function testStripeRejectionBecomesAGatewayExceptionWithStripesMessage(): void
    {
        $gateway = $this->gateway('sk_test_x', [new MockResponse('{"error":{"message":"No such payment_intent"}}', ['http_code' => 404])]);

        $this->expectException(StripeGatewayException::class);
        $this->expectExceptionMessage('No such payment_intent');
        $gateway->refundPaymentIntent('pi_x', 'k');
    }

    public function testNetworkFailureBecomesAGatewayExceptionWithoutLeakingTheKey(): void
    {
        $gateway = $this->gateway('sk_test_secret', [new MockResponse('', ['error' => 'timeout'])]);

        try {
            $gateway->createCustomer('a@b.it', 'u1');
            self::fail('Doveva fallire.');
        } catch (StripeGatewayException $e) {
            self::assertStringNotContainsString('sk_test_secret', $e->getMessage());
        }
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function gateway(string $key, array $responses): HttpStripeGateway
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new HttpStripeGateway($client, $key);
    }
}
