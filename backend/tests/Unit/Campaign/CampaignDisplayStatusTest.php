<?php

namespace App\Tests\Unit\Campaign;

use App\Campaign\Enum\CampaignDisplayStatus;
use App\Campaign\Enum\CampaignStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CampaignDisplayStatusTest extends TestCase
{
    private const NOW = '2026-11-10 12:00:00';

    /** @return iterable<string, array{CampaignStatus, string, int, ?string, CampaignDisplayStatus}> */
    public static function cases(): iterable
    {
        // stato interno, scadenza, quantità attuale (minimo = 10), chiusura → stato mostrato
        yield 'aperto, prima della scadenza' => [CampaignStatus::InCorso, '2026-11-11 12:00:00', 3, null, CampaignDisplayStatus::InCorso];
        yield 'minimo raggiunto ma non scaduto' => [CampaignStatus::Riuscita, '2026-11-11 12:00:00', 12, null, CampaignDisplayStatus::InCorso];
        yield 'scaduto senza minimo' => [CampaignStatus::InCorso, '2026-11-09 12:00:00', 3, null, CampaignDisplayStatus::NonRiuscita];
        yield 'scaduto con minimo, chiusura da fare' => [CampaignStatus::Riuscita, '2026-11-09 12:00:00', 12, null, CampaignDisplayStatus::InChiusura];
        yield 'scaduto con minimo, chiuso' => [CampaignStatus::Riuscita, '2026-11-09 12:00:00', 12, '2026-11-09 12:05:00', CampaignDisplayStatus::Conclusa];
        yield 'ordine pronto' => [CampaignStatus::OrdinePronto, '2026-11-09 12:00:00', 12, null, CampaignDisplayStatus::Conclusa];
        yield 'ordine al fornitore' => [CampaignStatus::OrdineFornitore, '2026-11-09 12:00:00', 12, null, CampaignDisplayStatus::Conclusa];
        yield 'consegnato' => [CampaignStatus::Consegnata, '2026-11-09 12:00:00', 12, null, CampaignDisplayStatus::Conclusa];
        yield 'fallito' => [CampaignStatus::Fallita, '2026-11-11 12:00:00', 3, null, CampaignDisplayStatus::NonRiuscita];
        yield 'annullato' => [CampaignStatus::Annullata, '2026-11-11 12:00:00', 12, null, CampaignDisplayStatus::NonRiuscita];
        yield 'scade proprio adesso' => [CampaignStatus::InCorso, self::NOW, 3, null, CampaignDisplayStatus::InCorso];
    }

    #[DataProvider('cases')]
    public function testResolve(CampaignStatus $status, string $deadline, int $quantity, ?string $closedAt, CampaignDisplayStatus $expected): void
    {
        $resolved = CampaignDisplayStatus::resolve(
            $status,
            new \DateTimeImmutable($deadline),
            $quantity,
            10,
            null === $closedAt ? null : new \DateTimeImmutable($closedAt),
            new \DateTimeImmutable(self::NOW),
        );

        self::assertSame($expected, $resolved);
    }
}
