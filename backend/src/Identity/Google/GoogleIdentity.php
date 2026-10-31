<?php

namespace App\Identity\Google;

/**
 * Identità letta da un token Google già verificato (firma, audience, scadenza).
 */
final readonly class GoogleIdentity
{
    public function __construct(
        public string $subject,
        public string $email,
        public bool $emailVerified,
        public string $firstName,
        public string $lastName,
    ) {
    }
}
