<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Product;
use App\Campaign\Entity\ProductImage;
use App\Campaign\Enum\ProductStatus;
use App\Identity\Entity\User;
use App\Supplier\Entity\Supplier;
use App\Tests\Support\GroupFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * US-21: cataloghi per il cliente collegato (fornitori pubblici, prodotti attivi, sedi di ritiro).
 */
final class CatalogTest extends WebTestCase
{
    use GroupFixtures;

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();
        $this->startFixtures($this->em);
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        while ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheCatalogsNeedALogin(): void
    {
        foreach (['suppliers', 'products', 'pickup-points'] as $catalog) {
            $this->client->request('GET', '/api/catalog/'.$catalog);
            self::assertResponseStatusCodeSame(401, $catalog);
        }
    }

    public function testOnlyPublicSuppliersAreListedWithTheirGroupCount(): void
    {
        $private = new Supplier('Fornitore riservato test');
        $private->setIsPublic(false);
        $this->em->persist($private);
        $this->group($this->em, quantity: 6, deadline: '+3 days');
        $this->group($this->em, quantity: 6, deadline: '+3 days');
        $this->em->flush();
        $this->client->loginUser($this->customer());

        $this->client->request('GET', '/api/catalog/suppliers');

        self::assertResponseStatusCodeSame(200);
        $byName = array_column($this->json()['dati'], null, 'nome');
        self::assertArrayHasKey('Fornitore chiusura test', $byName);
        self::assertArrayNotHasKey('Fornitore riservato test', $byName);
        self::assertSame(2, $byName['Fornitore chiusura test']['num_campagne']);
        self::assertSame($this->supplier->getId()->toRfc4122(), $byName['Fornitore chiusura test']['id']);
    }

    public function testOnlyActiveProductsAreListedAndCanBeFilteredBySupplier(): void
    {
        $other = new Supplier('Altro fornitore test');
        $active = (new Product($this->supplier, 'Prodotto attivo', 1000))->setBasePriceCents(1200);
        $archived = (new Product($this->supplier, 'Prodotto archiviato', 1000))->setStatus(ProductStatus::Archiviato);
        $elsewhere = new Product($other, 'Prodotto altrove', 500);
        foreach ([$other, $active, $archived, $elsewhere] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->persist((new ProductImage($active, 'img/secondaria.jpg'))->setPosition(2));
        $this->em->persist((new ProductImage($active, 'img/principale.jpg'))->setIsMain(true)->setPosition(5));
        $this->em->flush();
        $this->client->loginUser($this->customer());

        $this->client->request('GET', '/api/catalog/products?fornitore_id='.$this->supplier->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        $products = $this->json()['dati']['prodotti'];
        self::assertSame(['Prodotto attivo'], array_column($products, 'nome'));
        self::assertSame(1000, $products[0]['prezzo_unitario_cents']);
        self::assertSame(1200, $products[0]['prezzo_base_cents']);
        self::assertSame('img/principale.jpg', $products[0]['immagine']);
        self::assertSame($this->supplier->getId()->toRfc4122(), $products[0]['id_fornitore']);

        $this->client->request('GET', '/api/catalog/products');
        $names = array_column($this->json()['dati']['prodotti'], 'nome');
        self::assertContains('Prodotto attivo', $names);
        self::assertContains('Prodotto altrove', $names);
        self::assertNotContains('Prodotto archiviato', $names);
    }

    public function testAnInvalidSupplierFilterReturns400AndAnUnknownOneAnEmptyList(): void
    {
        $this->client->loginUser($this->customer());

        $this->client->request('GET', '/api/catalog/products?fornitore_id=non-un-uuid');
        self::assertResponseStatusCodeSame(400);
        self::assertSame('CAMPO_NON_VALIDO', $this->json()['errore']['codice']);

        $this->client->request('GET', '/api/catalog/products?fornitore_id=018f0000-0000-7000-8000-000000000000');
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->json()['dati']['prodotti']);
    }

    public function testPickupPointsAreListed(): void
    {
        $this->client->loginUser($this->customer());

        $this->client->request('GET', '/api/catalog/pickup-points');

        self::assertResponseStatusCodeSame(200);
        $byName = array_column($this->json()['dati'], null, 'nome');
        self::assertSame('Perugia', $byName['Sede chiusura test']['citta']);
        self::assertSame('Via Prova 1', $byName['Sede chiusura test']['indirizzo']);
        self::assertSame($this->pickupPoint->getId()->toRfc4122(), $byName['Sede chiusura test']['id_sede']);
    }

    private function customer(): User
    {
        $user = new User('Cliente', 'c'.++$this->fixtureCounter.'-'.bin2hex(random_bytes(3)).'@catalog-test.example');
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
