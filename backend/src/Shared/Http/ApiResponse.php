<?php

namespace App\Shared\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Forma unica delle risposte, come nel legacy (lib/risposta.php):
 *   { "ok": true,  "dati": ... }
 *   { "ok": false, "errore": { "codice": "...", "messaggio": "...", "campi"?: {...} } }
 */
final class ApiResponse
{
    public static function ok(mixed $data = null, int $status = 200): JsonResponse
    {
        return self::json(['ok' => true, 'dati' => $data], $status);
    }

    /**
     * @param array<string, string> $fields
     */
    public static function error(string $codice, string $message, int $status = 400, array $fields = []): JsonResponse
    {
        $error = ['codice' => $codice, 'messaggio' => $message];
        if ([] !== $fields) {
            $error['campi'] = $fields;
        }

        return self::json(['ok' => false, 'errore' => $error], $status);
    }

    public static function fromError(ApiError $error): JsonResponse
    {
        return self::error($error->codice, $error->getMessage(), $error->httpStatus, $error->fields);
    }

    /**
     * Corpo JSON della richiesta come array (vuoto se assente).
     *
     * @return array<string, mixed>
     */
    public static function body(Request $request): array
    {
        if ('' === $request->getContent()) {
            return [];
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            throw new ApiError('JSON_NON_VALIDO', "Il corpo non è JSON valido");
        }
    }

    private static function json(mixed $payload, int $status): JsonResponse
    {
        $response = new JsonResponse($payload, $status);
        $response->setEncodingOptions(\JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        return $response;
    }
}
