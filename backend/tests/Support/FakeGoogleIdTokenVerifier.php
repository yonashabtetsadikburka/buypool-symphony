<?php

namespace App\Tests\Support;

use App\Identity\Google\GoogleIdentity;
use App\Identity\Google\GoogleIdTokenVerifier;
use App\Shared\Http\ApiError;

/**
 * Verificatore di test: il token "valido" restituisce l'identità preparata dal test, ogni altro token è rifiutato.
 */
final class FakeGoogleIdTokenVerifier implements GoogleIdTokenVerifier
{
    public const VALID_TOKEN = 'valid-google-token';

    public static ?GoogleIdentity $identity = null;

    public function verify(string $idToken): GoogleIdentity
    {
        if (self::VALID_TOKEN !== $idToken || null === self::$identity) {
            throw new ApiError('TOKEN_GOOGLE_NON_VALIDO', 'Accesso con Google non riuscito', 401);
        }

        return self::$identity;
    }
}
