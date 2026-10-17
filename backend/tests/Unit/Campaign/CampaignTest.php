<?php

namespace App\Tests\Unit\Campaign;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Category;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\ProductStatus;
use App\Supplier\Entity\Supplier;
use PHPUnit\Framework\TestCase;

final class CampaignTest extends TestCase
{
    public function testCategoryEmptyNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Category('  ');
    }

    public function testProductDefaults(): void
    {
        $product = new Product(new Supplier('Acme Srl'), 'Zaino 20L', 1990);

        self::assertSame('Zaino 20L', $product->getName());
        self::assertSame(1990, $product->getUnitPriceCents());
        self::assertSame(1, $product->getMinQuantity());
        self::assertSame(ProductStatus::Attivo, $product->getStatus());
    }

    public function testProductNegativePriceIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Product(new Supplier('Acme Srl'), 'Zaino 20L', -1);
    }

    public function testProductMinQuantityMustBePositive(): void
    {
        $product = new Product(new Supplier('Acme Srl'), 'Zaino 20L', 1990);

        $this->expectException(\InvalidArgumentException::class);
        $product->setMinQuantity(0);
    }

    public function testCampaignDefaults(): void
    {
        $campaign = new Campaign(
            new Product(new Supplier('Acme Srl'), 'Zaino 20L', 1990),
            10,
            new \DateTimeImmutable('+10 days'),
            1990,
            1990,
        );

        self::assertSame(10, $campaign->getMoq());
        self::assertSame(0, $campaign->getCurrentQuantity());
        self::assertSame(CampaignStatus::InCorso, $campaign->getStatus());
        self::assertSame('10.00', $campaign->getCommissionRate());
    }

    public function testCampaignMoqMustBeAtLeastOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Campaign(
            new Product(new Supplier('Acme Srl'), 'Zaino 20L', 1990),
            0,
            new \DateTimeImmutable('+10 days'),
            1990,
            1990,
        );
    }

    public function testCampaignCurrentQuantityCannotBeNegative(): void
    {
        $campaign = new Campaign(
            new Product(new Supplier('Acme Srl'), 'Zaino 20L', 1990),
            10,
            new \DateTimeImmutable('+10 days'),
            1990,
            1990,
        );

        $this->expectException(\InvalidArgumentException::class);
        $campaign->setCurrentQuantity(-1);
    }

    public function testPriceTierThresholdMustBePositive(): void
    {
        $campaign = new Campaign(
            new Product(new Supplier('Acme Srl'), 'Zaino 20L', 1990),
            10,
            new \DateTimeImmutable('+10 days'),
            1990,
            1990,
        );

        $this->expectException(\InvalidArgumentException::class);
        new PriceTier($campaign, 0, 1500);
    }
}
