<?php

namespace App\Tests\Unit\Identity;

use App\Identity\Service\VatNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatNumberTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function vatNumbers(): iterable
    {
        yield 'valida' => ['12345678903', '12345678903'];
        yield 'con prefisso IT' => ['IT12345678903', '12345678903'];
        yield 'con spazi e minuscole' => ['it 1234 5678 903', '12345678903'];
        yield 'cifra di controllo errata' => ['12345678900', null];
        yield 'troppo corta' => ['1234567890', null];
        yield 'con lettere' => ['1234567890A', null];
        yield 'vuota' => ['', null];
    }

    #[DataProvider('vatNumbers')]
    public function testNormalize(string $input, ?string $expected): void
    {
        self::assertSame($expected, VatNumber::normalize($input));
    }
}
