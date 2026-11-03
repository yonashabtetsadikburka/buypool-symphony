<?php

namespace App\Tests\Unit\Campaign;

use App\Campaign\Application\CampaignActions;
use App\Campaign\Enum\CampaignStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CampaignActionsTest extends TestCase
{
    private const NOW = '2026-11-10 12:00:00';
    private const FUTURE = '2026-11-20 12:00:00';
    private const PAST = '2026-11-01 12:00:00';

    /** @return iterable<string, array{CampaignStatus, string, bool, bool, int, string, bool, bool, bool, bool}> */
    public static function cases(): iterable
    {
        // stato, scadenza, minimo raggiunto, chiuso, adesioni vive → situazione, tutto, testi, prolunga, elimina
        yield 'aperto senza adesioni' => [CampaignStatus::InCorso, self::FUTURE, false, false, 0, 'aperto', true, true, true, true];
        yield 'aperto con adesioni' => [CampaignStatus::InCorso, self::FUTURE, false, false, 3, 'aperto', false, true, true, false];
        yield 'attivo (minimo raggiunto)' => [CampaignStatus::Riuscita, self::FUTURE, true, false, 12, 'attivo', false, true, true, false];
        yield 'scaduto da chiudere' => [CampaignStatus::Riuscita, self::PAST, true, false, 12, 'scaduto_da_chiudere', false, true, false, false];
        yield 'scaduto senza minimo (non attivato)' => [CampaignStatus::InCorso, self::PAST, false, false, 3, 'bloccato', false, false, false, false];
        yield 'scaduto e già chiuso' => [CampaignStatus::Riuscita, self::PAST, true, true, 12, 'bloccato', false, false, false, false];
        yield 'ordine pronto' => [CampaignStatus::OrdinePronto, self::PAST, true, true, 12, 'bloccato', false, false, false, false];
        yield 'ordine al fornitore' => [CampaignStatus::OrdineFornitore, self::PAST, true, true, 12, 'bloccato', false, false, false, false];
        yield 'consegnato' => [CampaignStatus::Consegnata, self::PAST, true, true, 12, 'bloccato', false, false, false, false];
        yield 'fallito' => [CampaignStatus::Fallita, self::FUTURE, false, false, 0, 'bloccato', false, false, false, false];
        yield 'annullato, anche senza adesioni' => [CampaignStatus::Annullata, self::FUTURE, false, false, 0, 'bloccato', false, false, false, false];
    }

    #[DataProvider('cases')]
    public function testResolve(CampaignStatus $status, string $deadline, bool $reached, bool $closed, int $live, string $situation, bool $all, bool $texts, bool $extend, bool $delete): void
    {
        $actions = CampaignActions::resolve($status, new \DateTimeImmutable($deadline), $reached, $closed, $live, new \DateTimeImmutable(self::NOW));

        self::assertSame($situation, $actions->situation);
        self::assertSame($all, $actions->canEditEverything);
        self::assertSame($texts, $actions->canEditTexts);
        self::assertSame($extend, $actions->canExtendDeadline);
        self::assertSame($delete, $actions->canDelete);
    }

    public function testDeadlineExactlyNowCountsAsExpired(): void
    {
        $actions = CampaignActions::resolve(CampaignStatus::InCorso, new \DateTimeImmutable(self::NOW), false, false, 0, new \DateTimeImmutable(self::NOW));

        self::assertSame(CampaignActions::BLOCCATO, $actions->situation);
    }
}
