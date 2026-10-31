<?php

namespace App\Shared\Http;

/**
 * Errore di dominio dell'API: diventa la risposta { ok: false, errore: { codice, messaggio, campi? } }.
 */
final class ApiError extends \RuntimeException
{
    /**
     * @param array<string, string> $fields errori per campo, opzionali
     */
    public function __construct(
        public readonly string $codice,
        string $message = '',
        public readonly int $httpStatus = 400,
        public readonly array $fields = [],
    ) {
        parent::__construct('' !== $message ? $message : $codice);
    }
}
