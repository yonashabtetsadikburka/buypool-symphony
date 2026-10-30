<?php

namespace App\Tests\Unit\Pricing;

use App\Campaign\Entity\PriceTier;
use App\Delivery\Enum\DeliveryMode;
use App\Pricing\Exception\DepositBelowMinimumException;
use App\Pricing\PricingCalculator;
use App\Pricing\PricingSettings;
use App\Tests\Support\CampaignWorkflowFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PricingCalculatorTest extends TestCase
{
    private PricingCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new PricingCalculator(new PricingSettings(20, 490));
    }

    public function testDepositIsTwentyPercentOfTheFirstTierPriceTimesQuantity(): void
    {
        // 3 × 12,50 € × 20% = 7,50 €
        self::assertSame(750, $this->calculator->deposit(3, 1250));
        // 1 × 9,99 € × 20% = 1,998 € → 2,00 €
        self::assertSame(200, $this->calculator->deposit(1, 999));
        // 7 × 3,33 € × 20% = 4,662 € → 4,66 €
        self::assertSame(466, $this->calculator->deposit(7, 333));
    }

    public function testDepositBelowFiftyCentsIsRejected(): void
    {
        $this->expectException(DepositBelowMinimumException::class);

        $this->calculator->deposit(1, 200); // 0,40 €
    }

    public function testDepositOfExactlyFiftyCentsIsAccepted(): void
    {
        self::assertSame(50, $this->calculator->deposit(1, 250));
    }

    /** @return iterable<string, array{int, int, string, int, int, array<string, int>}> */
    public static function balanceCases(): iterable
    {
        // quantità, prezzo, commissione %, spedizione, acconto → merce, commissione, spedizione, totale, saldo
        yield 'ritiro in sede' => [5, 1000, '10.00', 0, 1000, ['goods' => 5000, 'commission' => 500, 'shipping' => 0, 'total' => 5500, 'balance' => 4500]];
        yield 'consegna a domicilio' => [2, 4500, '10.00', 490, 1800, ['goods' => 9000, 'commission' => 900, 'shipping' => 490, 'total' => 10390, 'balance' => 8590]];
        yield 'commissione arrotondata half up' => [3, 333, '10.00', 0, 200, ['goods' => 999, 'commission' => 100, 'shipping' => 0, 'total' => 1099, 'balance' => 899]];
        yield 'commissione con decimali' => [1, 1999, '7.50', 0, 400, ['goods' => 1999, 'commission' => 150, 'shipping' => 0, 'total' => 2149, 'balance' => 1749]];
        yield 'saldo negativo ammesso' => [1, 1000, '0.00', 0, 1500, ['goods' => 1000, 'commission' => 0, 'shipping' => 0, 'total' => 1000, 'balance' => -500]];
        yield 'spedizione negativa ignorata' => [1, 1000, '10.00', -300, 0, ['goods' => 1000, 'commission' => 100, 'shipping' => 0, 'total' => 1100, 'balance' => 1100]];
    }

    /**
     * @param array<string, int> $expected
     */
    #[DataProvider('balanceCases')]
    public function testBalanceBreakdown(int $quantity, int $price, string $rate, int $shipping, int $deposit, array $expected): void
    {
        $balance = $this->calculator->balance($quantity, $price, $rate, $shipping, $deposit);

        self::assertSame($expected['goods'], $balance->goodsCents);
        self::assertSame($expected['commission'], $balance->commissionCents);
        self::assertSame($expected['shipping'], $balance->shippingCents);
        self::assertSame($expected['total'], $balance->totalCents);
        self::assertSame($deposit, $balance->depositCents);
        self::assertSame($expected['balance'], $balance->balanceCents);
        self::assertSame($balance->totalCents - $deposit, $balance->balanceCents);
    }

    /**
     * Confronto con la formula del legacy (lib/prezzi.php, in euro e float con round(x, 2)):
     * i valori devono coincidere al centesimo.
     */
    public function testBalanceAndDepositMatchLegacyFloatFormulaToTheCent(): void
    {
        mt_srand(20261119);
        for ($i = 0; $i < 20000; ++$i) {
            $quantity = mt_rand(1, 99);
            $priceCents = mt_rand(100, 250000);
            $rate = mt_rand(0, 3000) / 100;
            $shippingCents = mt_rand(0, 1) * 490;
            $depositCents = mt_rand(0, 50000);

            $legacy = $this->legacySaldoDettaglio($quantity, $priceCents / 100, $rate, $shippingCents / 100, $depositCents / 100);
            $balance = $this->calculator->balance($quantity, $priceCents, number_format($rate, 2, '.', ''), $shippingCents, $depositCents);

            $context = sprintf('q=%d prezzo=%d comm=%s sped=%d acc=%d', $quantity, $priceCents, $rate, $shippingCents, $depositCents);
            self::assertSame((int) round($legacy['merce'] * 100), $balance->goodsCents, $context);
            self::assertSame((int) round($legacy['commissione'] * 100), $balance->commissionCents, $context);
            self::assertSame((int) round($legacy['totale'] * 100), $balance->totalCents, $context);
            self::assertSame((int) round($legacy['saldo'] * 100), $balance->balanceCents, $context);

            $legacyDeposit = round($quantity * ($priceCents / 100) * 20 / 100, 2);
            if ($legacyDeposit >= 0.50) {
                self::assertSame((int) round($legacyDeposit * 100), $this->calculator->deposit($quantity, $priceCents), $context);
            }
        }
    }

    public function testShippingIsChargedOnlyForHomeDelivery(): void
    {
        self::assertSame(490, $this->calculator->shipping(DeliveryMode::ConsegnaDomicilio));
        self::assertSame(0, $this->calculator->shipping(DeliveryMode::RitiroSede));
        self::assertSame(0, $this->calculator->shipping(null));
    }

    public function testReachedTierPriceUsesTheHighestReachedTierRegardlessOfOrder(): void
    {
        $tiers = $this->tiers([30 => 800, 10 => 1000, 20 => 900]);

        self::assertSame(1200, $this->calculator->reachedTierPrice($tiers, 9, 1200));
        self::assertSame(1000, $this->calculator->reachedTierPrice($tiers, 10, 1200));
        self::assertSame(900, $this->calculator->reachedTierPrice($tiers, 29, 1200));
        self::assertSame(800, $this->calculator->reachedTierPrice($tiers, 500, 1200));
        self::assertSame(1200, $this->calculator->reachedTierPrice([], 500, 1200));
    }

    public function testMinimumQuantityIsTheFirstTierThresholdOtherwiseTheMoq(): void
    {
        self::assertSame(10, $this->calculator->minimumQuantity($this->tiers([30 => 800, 10 => 1000]), 50));
        self::assertSame(50, $this->calculator->minimumQuantity([], 50));
        self::assertSame(1, $this->calculator->minimumQuantity([], 0));
        self::assertTrue($this->calculator->isBelowMinimum($this->tiers([10 => 1000]), 50, 9));
        self::assertFalse($this->calculator->isBelowMinimum($this->tiers([10 => 1000]), 50, 10));
    }

    public function testBalancePriceKeepsThePriceFixedAtClosure(): void
    {
        $tiers = $this->tiers([10 => 1000, 20 => 900]);

        self::assertSame(900, $this->calculator->balancePrice(900, $tiers, 5, 1200));
        self::assertSame(1000, $this->calculator->balancePrice(null, $tiers, 10, 1200));
        self::assertSame(1000, $this->calculator->balancePrice(0, $tiers, 10, 1200));
    }

    public function testEstimatePriceUsesTheFirstTierUntilTheMinimumIsReached(): void
    {
        $tiers = $this->tiers([10 => 1000, 20 => 900]);

        self::assertSame(1000, $this->calculator->estimatePrice($tiers, 3, 1200));
        self::assertSame(900, $this->calculator->estimatePrice($tiers, 25, 1200));
        self::assertSame(1200, $this->calculator->estimatePrice([], 25, 1200));
    }

    /**
     * Implementazione di riferimento: copia di saldo_dettaglio() del legacy.
     *
     * @return array{merce: float, commissione: float, totale: float, saldo: float}
     */
    private function legacySaldoDettaglio(int $quantita, float $prezzoUnitario, float $commissionePct, float $spedizione, float $accontoVersato): array
    {
        $merce = round($quantita * $prezzoUnitario, 2);
        $commissione = round($merce * $commissionePct / 100, 2);
        $spedizione = round(max(0.0, $spedizione), 2);
        $totale = round($merce + $commissione + $spedizione, 2);
        $acconto = round($accontoVersato, 2);

        return ['merce' => $merce, 'commissione' => $commissione, 'totale' => $totale, 'saldo' => round($totale - $acconto, 2)];
    }

    /**
     * @param array<int, int> $thresholdToPrice
     *
     * @return list<PriceTier>
     */
    private function tiers(array $thresholdToPrice): array
    {
        $campaign = CampaignWorkflowFactory::createCampaign(\App\Campaign\Enum\CampaignStatus::InCorso, 0, 5);

        $tiers = [];
        foreach ($thresholdToPrice as $threshold => $price) {
            $tiers[] = new PriceTier($campaign, $threshold, $price);
        }

        return $tiers;
    }
}
