<?php

namespace App\Tests\Integration\Campaign;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Category;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Proposal\Entity\ProductLike;
use App\Supplier\Entity\Supplier;
use App\Tests\Integration\DatabaseTestCase;
use App\Identity\Entity\User;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class CampaignPersistenceTest extends DatabaseTestCase
{
    private function makeProduct(): Product
    {
        $supplier = new Supplier('Acme Srl');
        $this->em->persist($supplier);

        $category = new Category('Accessori');
        $this->em->persist($category);

        $product = new Product($supplier, 'Zaino 20L', 1990);
        $product->setCategory($category);
        $this->em->persist($product);

        return $product;
    }

    public function testCategoryNameIsUnique(): void
    {
        $this->em->persist(new Category('Accessori'));
        $this->em->persist(new Category('Accessori'));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testFindByStatus(): void
    {
        $product = $this->makeProduct();

        $open = new Campaign($product, 10, new \DateTimeImmutable('+10 days'), 1990, 1990);
        $this->em->persist($open);

        $closed = new Campaign($product, 10, new \DateTimeImmutable('+10 days'), 1990, 1990);
        $closed->setStatus(CampaignStatus::Consegnata);
        $this->em->persist($closed);

        $this->em->flush();
        $this->em->clear();

        $repository = $this->em->getRepository(Campaign::class);
        self::assertCount(1, $repository->findByStatus(CampaignStatus::InCorso));
    }

    public function testPriceTierIsUniquePerCampaign(): void
    {
        $product = $this->makeProduct();
        $campaign = new Campaign($product, 10, new \DateTimeImmutable('+10 days'), 1990, 1990);
        $this->em->persist($campaign);
        $this->em->persist(new PriceTier($campaign, 10, 1790));
        $this->em->flush();

        $this->em->persist(new PriceTier($campaign, 10, 1690));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testLikeIsUniquePerUserAndProduct(): void
    {
        $user = new User('Mario', 'mario@example.com');
        $this->em->persist($user);
        $product = $this->makeProduct();

        $this->em->persist(new ProductLike($user, $product));
        $this->em->flush();

        $this->em->persist(new ProductLike($user, $product));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
