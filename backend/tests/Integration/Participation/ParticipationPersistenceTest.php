<?php

namespace App\Tests\Integration\Participation;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Product;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Payment\Entity\Payment;
use App\Payment\Entity\StripeEvent;
use App\Payment\Enum\PaymentType;
use App\Supplier\Entity\Supplier;
use App\Tests\Integration\DatabaseTestCase;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class ParticipationPersistenceTest extends DatabaseTestCase
{
    private function makeCampaign(): Campaign
    {
        $supplier = new Supplier('Acme Srl');
        $this->em->persist($supplier);
        $product = new Product($supplier, 'Zaino 20L', 1990);
        $this->em->persist($product);
        $campaign = new Campaign($product, 10, new \DateTimeImmutable('+10 days'), 1990, 1990);
        $this->em->persist($campaign);

        return $campaign;
    }

    public function testOneParticipationPerUserAndCampaign(): void
    {
        $campaign = $this->makeCampaign();
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $this->em->persist(new Participation($campaign, $user, 2));
        $this->em->flush();

        $this->em->persist(new Participation($campaign, $user, 3));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testFindOneByCampaignAndUser(): void
    {
        $campaign = $this->makeCampaign();
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $this->em->persist(new Participation($campaign, $user, 2));
        $this->em->flush();
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneByEmail('mario@example.com');
        $campaign = $this->em->getRepository(Campaign::class)->findAll()[0];

        $repository = $this->em->getRepository(Participation::class);
        $found = $repository->findOneByCampaignAndUser($campaign, $user);

        self::assertInstanceOf(Participation::class, $found);
        self::assertSame(2, $found->getQuantity());
    }

    public function testStripeEventIdIsUnique(): void
    {
        $this->em->persist(new StripeEvent('evt_123', 'payment_intent.succeeded'));
        $this->em->flush();

        $this->em->persist(new StripeEvent('evt_123', 'payment_intent.succeeded'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testStripePaymentIntentIdIsUnique(): void
    {
        $campaign = $this->makeCampaign();
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $participation = new Participation($campaign, $user, 2);
        $this->em->persist($participation);

        $first = new Payment($participation, PaymentType::Acconto, 1990);
        $first->setStripePaymentIntentId('pi_123');
        $this->em->persist($first);
        $this->em->flush();

        $second = new Payment($participation, PaymentType::Saldo, 1990);
        $second->setStripePaymentIntentId('pi_123');
        $this->em->persist($second);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
