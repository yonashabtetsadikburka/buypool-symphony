<?php

namespace App\Tests\Unit\Identity;

use App\Identity\Google\TokenInfoGoogleIdTokenVerifier;
use App\Shared\Http\ApiError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class TokenInfoGoogleIdTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = 'client-id.apps.googleusercontent.com';

    public function testValidTokenReturnsTheIdentity(): void
    {
        $identity = $this->verifier($this->claims())->verify('token');

        self::assertSame('sub-123', $identity->subject);
        self::assertSame('anna@example.com', $identity->email);
        self::assertTrue($identity->emailVerified);
        self::assertSame('Anna', $identity->firstName);
        self::assertSame('Bianchi', $identity->lastName);
    }

    public function testNameIsSplitWhenGivenAndFamilyNameAreMissing(): void
    {
        $identity = $this->verifier($this->claims(['given_name' => '', 'family_name' => '', 'name' => 'Anna Maria Bianchi']))->verify('token');

        self::assertSame('Anna', $identity->firstName);
        self::assertSame('Maria Bianchi', $identity->lastName);
    }

    public function testUnverifiedEmailIsReported(): void
    {
        self::assertFalse($this->verifier($this->claims(['email_verified' => 'false']))->verify('token')->emailVerified);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidClaims(): iterable
    {
        yield 'audience di un altra applicazione' => [['aud' => 'altro-client']];
        yield 'emittente sconosciuto' => [['iss' => 'https://evil.example']];
        yield 'scaduto' => [['exp' => (string) (self::NOW - 1)]];
        yield 'senza sub' => [['sub' => '']];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidClaims')]
    public function testInvalidClaimsAreRejected(array $override): void
    {
        $this->expectException(ApiError::class);
        $this->expectExceptionMessage('Accesso con Google non riuscito');

        $this->verifier($this->claims($override))->verify('token');
    }

    public function testGoogleRejectingTheTokenIsReportedAs401(): void
    {
        $verifier = new TokenInfoGoogleIdTokenVerifier(
            new MockHttpClient(new MockResponse('{"error":"invalid_token"}', ['http_code' => 400])),
            new MockClock('@'.self::NOW),
            self::CLIENT_ID,
        );

        try {
            $verifier->verify('token');
            self::fail('Expected ApiError.');
        } catch (ApiError $e) {
            self::assertSame(401, $e->httpStatus);
            self::assertSame('TOKEN_GOOGLE_NON_VALIDO', $e->codice);
        }
    }

    public function testMissingClientIdDisablesGoogleLogin(): void
    {
        $verifier = new TokenInfoGoogleIdTokenVerifier(new MockHttpClient(), new MockClock('@'.self::NOW), '');

        try {
            $verifier->verify('token');
            self::fail('Expected ApiError.');
        } catch (ApiError $e) {
            self::assertSame(503, $e->httpStatus);
        }
    }

    private const NOW = 1_800_000_000;

    /**
     * @param array<string, mixed> $override
     *
     * @return array<string, mixed>
     */
    private function claims(array $override = []): array
    {
        return $override + [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'sub-123',
            'exp' => (string) (self::NOW + 3600),
            'email' => 'Anna@Example.com',
            'email_verified' => 'true',
            'given_name' => 'Anna',
            'family_name' => 'Bianchi',
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function verifier(array $claims): TokenInfoGoogleIdTokenVerifier
    {
        return new TokenInfoGoogleIdTokenVerifier(
            new MockHttpClient(new MockResponse(json_encode($claims, \JSON_THROW_ON_ERROR))),
            new MockClock('@'.self::NOW),
            self::CLIENT_ID,
        );
    }
}
