<?php

namespace App\Identity\Google;

use App\Shared\Http\ApiError;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifica il token chiedendo a Google (endpoint tokeninfo), che controlla la firma;
 * qui si controllano audience, emittente e scadenza.
 */
final class TokenInfoGoogleIdTokenVerifier implements GoogleIdTokenVerifier
{
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly ClockInterface $clock,
        #[Autowire('%env(GOOGLE_CLIENT_ID)%')]
        private readonly string $clientId,
    ) {
    }

    public function verify(string $idToken): GoogleIdentity
    {
        if ('' === $this->clientId) {
            throw new ApiError('GOOGLE_NON_CONFIGURATO', "L'accesso con Google non è disponibile", 503);
        }

        try {
            $response = $this->httpClient->request('GET', self::TOKENINFO_URL, ['query' => ['id_token' => $idToken], 'timeout' => 5]);
            $claims = 200 === $response->getStatusCode() ? $response->toArray(false) : null;
        } catch (\Throwable) {
            $claims = null;
        }

        if (null === $claims
            || ($claims['aud'] ?? null) !== $this->clientId
            || !\in_array($claims['iss'] ?? null, self::ISSUERS, true)
            || (int) ($claims['exp'] ?? 0) <= $this->clock->now()->getTimestamp()
            || !\is_string($claims['sub'] ?? null) || '' === $claims['sub']
        ) {
            throw new ApiError('TOKEN_GOOGLE_NON_VALIDO', 'Accesso con Google non riuscito', 401);
        }

        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        $verified = \in_array($claims['email_verified'] ?? false, [true, 'true'], true);
        $firstName = trim((string) ($claims['given_name'] ?? ''));
        $lastName = trim((string) ($claims['family_name'] ?? ''));
        if ('' === $firstName && '' === $lastName && '' !== trim((string) ($claims['name'] ?? ''))) {
            [$firstName, $lastName] = array_pad(preg_split('/\s+/', trim((string) $claims['name']), 2) ?: [], 2, '');
        }
        if ('' === $firstName && '' !== $email) {
            $firstName = explode('@', $email)[0];
        }

        return new GoogleIdentity($claims['sub'], $email, $verified && '' !== $email, mb_substr($firstName, 0, 100), mb_substr($lastName, 0, 100));
    }
}
