<?php

namespace App\Identity\Google;

use App\Shared\Http\ApiError;

interface GoogleIdTokenVerifier
{
    /**
     * @throws ApiError se il token non è valido, è scaduto o non è per questa applicazione
     */
    public function verify(string $idToken): GoogleIdentity;
}
