<?php

namespace App\Pricing;

use App\Campaign\Entity\PriceTier;
use App\Delivery\Enum\DeliveryMode;
use App\Pricing\Exception\DepositBelowMinimumException;

/**
 * Formule di prezzo, acconto e saldo: funzioni pure, senza database, tutto in centesimi.
 * Porta di lib/prezzi.php e lib/aumento.php del legacy: stessi risultati al centesimo
 * (ogni voce arrotondata "half up", come round($x, 2) del legacy).
 */
final class PricingCalculator
{
    public const MINIMUM_DEPOSIT_CENTS = 50;

    public function __construct(private readonly PricingSettings $settings)
    {
    }

    /** Acconto = quantità × prezzo del primo scaglione × percentuale, minimo 0,50 €. */
    public function deposit(int $quantity, int $firstTierPriceCents): int
    {
        $cents = self::roundDiv($quantity * $firstTierPriceCents * self::hundredths($this->settings->depositPercent), 10000);

        if ($cents < self::MINIMUM_DEPOSIT_CENTS) {
            throw new DepositBelowMinimumException($cents, self::MINIMUM_DEPOSIT_CENTS);
        }

        return $cents;
    }

    /**
     * saldo = quantità × prezzo finale + commissione % sulla merce + spedizione − acconti versati.
     *
     * @param string|int|float $commissionRate percentuale del gruppo (es. "10.00")
     */
    public function balance(int $quantity, int $unitPriceCents, string|int|float $commissionRate, int $shippingCents, int $depositPaidCents): BalanceBreakdown
    {
        $goods = $quantity * $unitPriceCents;
        $commission = self::roundDiv($goods * self::hundredths($commissionRate), 10000);
        $shipping = max(0, $shippingCents);
        $total = $goods + $commission + $shipping;

        return new BalanceBreakdown($goods, $commission, $shipping, $total, $depositPaidCents, $total - $depositPaidCents);
    }

    /** Spedizione da addebitare: solo la consegna a domicilio costa. */
    public function shipping(?DeliveryMode $mode): int
    {
        return DeliveryMode::ConsegnaDomicilio === $mode ? max(0, $this->settings->homeDeliveryCents) : 0;
    }

    /**
     * Prezzo dello scaglione più alto raggiunto da $totalQuantity unità (default: prezzo corrente del gruppo).
     *
     * @param iterable<PriceTier> $tiers
     */
    public function reachedTierPrice(iterable $tiers, int $totalQuantity, int $currentPriceCents): int
    {
        $price = $currentPriceCents;
        foreach (self::sorted($tiers) as $tier) {
            if ($totalQuantity >= $tier->getThreshold()) {
                $price = $tier->getPriceCents();
            }
        }

        return $price;
    }

    /**
     * Quantità minima del gruppo: soglia del primo scaglione, altrimenti il MOQ (almeno 1).
     *
     * @param iterable<PriceTier> $tiers
     */
    public function minimumQuantity(iterable $tiers, int $moq): int
    {
        $sorted = self::sorted($tiers);

        return max(1, [] !== $sorted ? $sorted[0]->getThreshold() : $moq);
    }

    /** @param iterable<PriceTier> $tiers */
    public function isBelowMinimum(iterable $tiers, int $moq, int $quantity): bool
    {
        return $quantity < $this->minimumQuantity($tiers, $moq);
    }

    /**
     * Prezzo unitario del saldo: quello FISSATO alla chiusura se noto, altrimenti lo scaglione raggiunto.
     * Il prezzo fissato non cambia più anche se le unità scendono.
     *
     * @param iterable<PriceTier> $tiers
     */
    public function balancePrice(?int $finalPriceCents, iterable $tiers, int $quantity, int $currentPriceCents): int
    {
        return null !== $finalPriceCents && $finalPriceCents > 0
            ? $finalPriceCents
            : $this->reachedTierPrice($tiers, $quantity, $currentPriceCents);
    }

    /**
     * Prezzo per STIMARE il saldo di un gruppo aperto: minimo raggiunto → scaglione corrente,
     * altrimenti il primo scaglione.
     *
     * @param iterable<PriceTier> $tiers
     */
    public function estimatePrice(iterable $tiers, int $totalQuantity, int $currentPriceCents): int
    {
        $sorted = self::sorted($tiers);
        if ([] === $sorted) {
            return $currentPriceCents;
        }

        return $totalQuantity >= $sorted[0]->getThreshold()
            ? $this->reachedTierPrice($sorted, $totalQuantity, $currentPriceCents)
            : $sorted[0]->getPriceCents();
    }

    /**
     * @param iterable<PriceTier> $tiers
     *
     * @return list<PriceTier>
     */
    private static function sorted(iterable $tiers): array
    {
        $list = [...$tiers];
        usort($list, static fn (PriceTier $a, PriceTier $b): int => $a->getThreshold() <=> $b->getThreshold());

        return $list;
    }

    /** Percentuale in centesimi di punto percentuale: "10.00" → 1000. */
    private static function hundredths(string|int|float $percent): int
    {
        return (int) round((float) $percent * 100);
    }

    /** numeratore / denominatore arrotondato al più vicino, metà verso l'alto (valori non negativi). */
    private static function roundDiv(int $numerator, int $denominator): int
    {
        return intdiv($numerator * 2 + $denominator, $denominator * 2);
    }
}
