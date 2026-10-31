<?php

namespace App\Identity\Service;

/**
 * Partita IVA italiana: 11 cifre con cifra di controllo (prefisso IT facoltativo).
 * Porta di piva_normalizza() del legacy.
 */
final class VatNumber
{
    /** Le 11 cifre normalizzate, null se la partita IVA non è valida. */
    public static function normalize(string $input): ?string
    {
        $vat = strtoupper((string) preg_replace('/[\s.\-]/', '', $input));
        if (str_starts_with($vat, 'IT')) {
            $vat = substr($vat, 2);
        }
        if (1 !== preg_match('/^\d{11}$/', $vat)) {
            return null;
        }

        $sum = 0;
        for ($i = 0; $i < 10; ++$i) {
            $digit = (int) $vat[$i];
            if (1 === $i % 2) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }

        return ((10 - $sum % 10) % 10) === (int) $vat[10] ? $vat : null;
    }
}
