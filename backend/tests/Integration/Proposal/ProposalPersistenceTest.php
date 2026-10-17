<?php

namespace App\Tests\Integration\Proposal;

use App\Identity\Entity\User;
use App\Proposal\Entity\Proposal;
use App\Proposal\Entity\ProposalTier;
use App\Proposal\Entity\ProposalVote;
use App\Proposal\Enum\ProposalStatus;
use App\Proposal\Enum\VoteValue;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class ProposalPersistenceTest extends DatabaseTestCase
{
    public function testFindByStatus(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);

        $pending = new Proposal($user, 'Zaino termico');
        $this->em->persist($pending);

        $published = new Proposal($user, 'Tenda da campeggio');
        $published->setStatus(ProposalStatus::Pubblicata);
        $this->em->persist($published);

        $this->em->flush();
        $this->em->clear();

        $repository = $this->em->getRepository(Proposal::class);
        $result = $repository->findByStatus(ProposalStatus::InAttesa);

        self::assertCount(1, $result);
        self::assertSame('Zaino termico', $result[0]->getTitle());
    }

    public function testTierThresholdIsUniquePerProposal(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $proposal = new Proposal($user, 'Zaino termico');
        $this->em->persist($proposal);
        $this->em->persist(new ProposalTier($proposal, 10, 1500));
        $this->em->flush();

        $this->em->persist(new ProposalTier($proposal, 10, 1200));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testOneVotePerVoterPerProposal(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $proposal = new Proposal($user, 'Zaino termico');
        $this->em->persist($proposal);
        $this->em->persist(new ProposalVote($proposal, $user, VoteValue::Favore));
        $this->em->flush();

        $this->em->persist(new ProposalVote($proposal, $user, VoteValue::Contrario));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
