<?php

namespace App\Tests\Unit\Identity;

use App\Identity\Service\PasswordPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function passwords(): iterable
    {
        yield 'valida' => ['abcdefg1', true];
        yield 'con accenti' => ['àèìòù123', true];
        yield 'troppo corta' => ['abc1', false];
        yield 'sette caratteri' => ['abcdef1', false];
        yield 'senza numero' => ['abcdefgh', false];
        yield 'senza lettera' => ['12345678', false];
        yield 'limite massimo' => [str_repeat('a', 127).'1', true];
        yield 'oltre il massimo' => [str_repeat('a', 128).'1', false];
    }

    #[DataProvider('passwords')]
    public function testPasswordRequirements(string $password, bool $valid): void
    {
        self::assertSame($valid, PasswordPolicy::isValid($password));
    }
}
