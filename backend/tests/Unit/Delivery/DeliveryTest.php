<?php

namespace App\Tests\Unit\Delivery;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Product;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Entity\PickupToken;
use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Enum\DeliveryStatus;
use App\Delivery\Enum\PickupTokenStatus;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Supplier\Entity\Supplier;
use PHPUnit\Framework\TestCase;

final class DeliveryTest extends TestCase
{
    private function makeParticipation(): Participation
    {
        $campaign = new Campaign(
            new Product(new Supplier('Acme Srl'), 'Zaino 20L', 1990),
            10,
            new \DateTimeImmutable('+10 days'),
            1990,
            1990,
        );

        return new Participation($campaign, new User('Mario', 'mario@example.com'), 3);
    }

    public function testPickupPointRequiresValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PickupPoint('', 'Via Roma 1', 'Milano');
    }

    public function testPickupTokenAssignedQuantityMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PickupToken($this->makeParticipation(), hash('sha256', 'token'), 0);
    }

    public function testPickupTokenRotationChangesHash(): void
    {
        $token = new PickupToken($this->makeParticipation(), hash('sha256', 'a'), 3);
        $token->rotateTokenHash(hash('sha256', 'b'));

        self::assertSame(hash('sha256', 'b'), $token->getTokenHash());
        self::assertSame(PickupTokenStatus::Generato, $token->getStatus());

        $token->markScanned();
        self::assertSame(PickupTokenStatus::Scansionato, $token->getStatus());
        self::assertNotNull($token->getScannedAt());
    }

    public function testDeliveryDefaults(): void
    {
        $delivery = new Delivery($this->makeParticipation(), DeliveryMode::RitiroSede);

        self::assertSame(DeliveryMode::RitiroSede, $delivery->getMode());
        self::assertSame(DeliveryStatus::InAttesa, $delivery->getStatus());
        self::assertSame(0, $delivery->getDeliveryCostCents());
    }

    public function testDeliveryCostCannotBeNegative(): void
    {
        $delivery = new Delivery($this->makeParticipation(), DeliveryMode::ConsegnaDomicilio);

        $this->expectException(\InvalidArgumentException::class);
        $delivery->setDeliveryCostCents(-1);
    }
}
