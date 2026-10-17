<?php

namespace App\Tests\Unit\Proposal;

use App\Identity\Entity\User;
use App\Proposal\Entity\Proposal;
use App\Proposal\Entity\ProposalTier;
use App\Proposal\Enum\ProposalStatus;
use PHPUnit\Framework\TestCase;

final class ProposalTest extends TestCase
{
    public function testDefaults(): void
    {
        $proposal = new Proposal(new User('Mario', 'mario@example.com'), 'Zaino termico');

        self::assertSame('Zaino termico', $proposal->getTitle());
        self::assertSame(ProposalStatus::InAttesa, $proposal->getStatus());
        self::assertSame(0, $proposal->getVotesCount());
        self::assertSame('EUR', $proposal->getCurrency());
        self::assertNull($proposal->getCurrentPriceCents());
        self::assertCount(0, $proposal->getTiers());
    }

    public function testEmptyTitleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Proposal(new User('Mario', 'mario@example.com'), '   ');
    }

    public function testNegativeVotesAreRejected(): void
    {
        $proposal = new Proposal(new User('Mario', 'mario@example.com'), 'Zaino termico');

        $this->expectException(\InvalidArgumentException::class);
        $proposal->setVotesCount(-1);
    }

    public function testRequestedMoqMustBePositive(): void
    {
        $proposal = new Proposal(new User('Mario', 'mario@example.com'), 'Zaino termico');

        $this->expectException(\InvalidArgumentException::class);
        $proposal->setRequestedMoq(0);
    }

    public function testTierThresholdMustBePositive(): void
    {
        $proposal = new Proposal(new User('Mario', 'mario@example.com'), 'Zaino termico');

        $this->expectException(\InvalidArgumentException::class);
        new ProposalTier($proposal, 0, 1000);
    }

    public function testTierPriceMustBePositive(): void
    {
        $proposal = new Proposal(new User('Mario', 'mario@example.com'), 'Zaino termico');

        $this->expectException(\InvalidArgumentException::class);
        new ProposalTier($proposal, 10, 0);
    }
}
