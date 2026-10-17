<?php

namespace App\Tests\Unit\Participation;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Product;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Payment\Entity\Payment;
use App\Payment\Entity\StripeEvent;
use App\Payment\Enum\PaymentType;
use App\Supplier\Entity\Supplier;
use PHPUnit\Framework\TestCase;

final class ParticipationTest extends TestCase
{
    private function makeCampaign(): Campaign
    {
        return new Campaign(
            new Product(new Supplier('Acme Srl'), 'Zaino 20L', 1990),
            10,
            new \DateTimeImmutable('+10 days'),
            1990,
            1990,
        );
    }

    public function testQuantityMustBePositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Participation($this->makeCampaign(), new User('Mario', 'mario@example.com'), 0);
    }

    public function testNegativeDepositIsRejected(): void
    {
        $participation = new Participation($this->makeCampaign(), new User('Mario', 'mario@example.com'), 2);

        $this->expectException(\InvalidArgumentException::class);
        $participation->setDepositCents(-1);
    }

    public function testPaymentAmountCannotBeNegative(): void
    {
        $participation = new Participation($this->makeCampaign(), new User('Mario', 'mario@example.com'), 2);

        $this->expectException(\InvalidArgumentException::class);

        new Payment($participation, PaymentType::Acconto, -1);
    }

    public function testStripeEventIdCannotBeEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StripeEvent('  ', 'payment_intent.succeeded');
    }
}
