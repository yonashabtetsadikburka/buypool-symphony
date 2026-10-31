<?php

namespace App\Identity\Service;

/**
 * Password: da 8 a 128 caratteri, almeno una lettera e un numero (stesse regole del legacy).
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 8;
    public const MAX_LENGTH = 128;

    public static function isValid(string $password): bool
    {
        $length = mb_strlen($password);

        return $length >= self::MIN_LENGTH
            && $length <= self::MAX_LENGTH
            && 1 === preg_match('/\p{L}/u', $password)
            && 1 === preg_match('/\d/', $password);
    }
}
