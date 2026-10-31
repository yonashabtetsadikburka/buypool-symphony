<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Category;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Entity\ProductImage;
use App\Delivery\Entity\PickupPoint;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Supplier\Entity\Supplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CampaignCatalogTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        // Tutto quello che il test scrive viene annullato al termine.
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testGuestSeesTheListWithQuantitiesDeadlineStatusAndPrice(): void
    {
        $campaign = $this->createCampaign(moq: 10, quantity: 4, deadline: '+5 days', tiers: [10 => 900, 20 => 800]);

        $this->client->request('GET', '/api/campaigns');

        self::assertResponseStatusCodeSame(200);
        $item = $this->findById($this->json()['dati'], $campaign->getId()->toRfc4122());
        self::assertSame(10, $item['quantita_minima']);
        self::assertSame(4, $item['quantita_attuale']);
        self::assertFalse($item['soglia_raggiunta']);
        self::assertSame('in_corso', $item['stato']);
        self::assertSame('in_corso', $item['stato_campagna']);
        self::assertSame(1000, $item['prezzo_corrente_cents']);
        self::assertSame($campaign->getDeadline()->format(\DATE_ATOM), $item['data_limite']);
        self::assertSame('Zaino di prova', $item['prodotto']);
        self::assertSame('Fornitore di prova', $item['fornitore']);
    }

    public function testListIsOrderedByDeadline(): void
    {
        $later = $this->createCampaign(moq: 5, quantity: 0, deadline: '+30 days', name: 'Tardi');
        $sooner = $this->createCampaign(moq: 5, quantity: 0, deadline: '+2 days', name: 'Presto');

        $this->client->request('GET', '/api/campaigns');

        $ids = array_column($this->json()['dati'], 'id');
        self::assertLessThan(array_search($later->getId()->toRfc4122(), $ids, true), array_search($sooner->getId()->toRfc4122(), $ids, true));
    }

    public function testDetailShowsTiersOrderedByThreshold(): void
    {
        $campaign = $this->createCampaign(moq: 10, quantity: 12, deadline: '+3 days', tiers: [30 => 700, 10 => 900, 20 => 800]);

        $this->client->request('GET', '/api/campaigns/'.$campaign->getId()->toRfc4122());

        self::assertResponseStatusCodeSame(200);
        $dati = $this->json()['dati'];
        self::assertSame([10, 20, 30], array_column($dati['scaglioni'], 'soglia'));
        self::assertSame([900, 800, 700], array_column($dati['scaglioni'], 'prezzo_cents'));
        self::assertSame(490, $dati['costo_spedizione_cents']);
        self::assertSame('Fornitore di prova', $dati['fornitore_info']['nome']);
        self::assertSame(1, $dati['fornitore_info']['num_campagne']);
        self::assertTrue($dati['soglia_raggiunta']);
    }

    public function testDetailListsImagesWithTheMainOneFirstAndCategory(): void
    {
        $campaign = $this->createCampaign(moq: 5, quantity: 0, deadline: '+3 days');

        $this->client->request('GET', '/api/campaigns/'.$campaign->getId()->toRfc4122());

        $dati = $this->json()['dati'];
        self::assertSame('/img/principale.jpg', $dati['immagine']);
        self::assertSame(['/img/principale.jpg', '/img/seconda.jpg'], array_column($dati['immagini'], 'url'));
        self::assertSame('Categoria di prova', $dati['categoria']);
    }

    public function testParticipantsAreCountedWithoutExposingPersonalData(): void
    {
        $campaign = $this->createCampaign(moq: 5, quantity: 3, deadline: '+3 days');
        $this->participate($campaign, 'uno', ParticipationStatus::Prenotata);
        $this->participate($campaign, 'due', ParticipationStatus::Pagata);
        $this->participate($campaign, 'tre', ParticipationStatus::Rimborsata);

        $this->client->request('GET', '/api/campaigns/'.$campaign->getId()->toRfc4122());

        $content = (string) $this->client->getResponse()->getContent();
        self::assertSame(2, $this->json()['dati']['partecipanti']);
        self::assertStringNotContainsString('uno@', $content);
        self::assertStringNotContainsString('Mario', $content);
        self::assertArrayNotHasKey('partecipazioni', $this->json()['dati']);
    }

    public function testCampaignWithoutPickupPointFallsBackToTheDefaultOne(): void
    {
        $this->em->persist(new PickupPoint('Sede predefinita di prova', 'Via Prova 1', 'Perugia'));
        $campaign = $this->createCampaign(moq: 5, quantity: 0, deadline: '+3 days');

        $this->client->request('GET', '/api/campaigns/'.$campaign->getId()->toRfc4122());

        $dati = $this->json()['dati'];
        self::assertTrue($dati['ritiro_disponibile']);
        self::assertNotNull($dati['punto_ritiro']);
    }

    public function testExpiredCampaignWithoutMinimumIsShownAsNotSuccessful(): void
    {
        $campaign = $this->createCampaign(moq: 10, quantity: 3, deadline: '-1 day', tiers: [10 => 900]);

        $this->client->request('GET', '/api/campaigns/'.$campaign->getId()->toRfc4122());

        self::assertSame('non_riuscita', $this->json()['dati']['stato_campagna']);
    }

    public function testListAndDetailDoNotChangeAnything(): void
    {
        $campaign = $this->createCampaign(moq: 10, quantity: 3, deadline: '-1 day', tiers: [10 => 900]);
        $this->em->flush();
        $this->em->clear();
        $id = $campaign->getId()->toRfc4122();

        $this->client->request('GET', '/api/campaigns');
        $this->client->request('GET', '/api/campaigns/'.$id);

        $reloaded = $this->em->getRepository(Campaign::class)->find($campaign->getId());
        self::assertSame('in_corso', $reloaded?->getStatus()->value);
    }

    public function testUnknownCampaignReturns404(): void
    {
        $this->client->request('GET', '/api/campaigns/018f0000-0000-7000-8000-000000000000');

        self::assertResponseStatusCodeSame(404);
        self::assertSame('CAMPAGNA_INESISTENTE', $this->json()['errore']['codice']);
    }

    public function testMalformedIdReturns404(): void
    {
        $this->client->request('GET', '/api/campaigns/non-un-uuid');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @param array<int, int> $tiers soglia → prezzo in centesimi
     */
    private function createCampaign(int $moq, int $quantity, string $deadline, array $tiers = [], string $name = 'Zaino di prova'): Campaign
    {
        $supplier = new Supplier('Fornitore di prova');
        $category = $this->em->getRepository(Category::class)->findOneBy(['name' => 'Categoria di prova']) ?? new Category('Categoria di prova');
        $product = (new Product($supplier, $name, 1200))->setCategory($category)->setDescription('Descrizione');
        $campaign = new Campaign($product, $moq, new \DateTimeImmutable($deadline), 1200, 1000);
        $campaign->setCurrentQuantity($quantity);
        foreach ($tiers as $threshold => $price) {
            $campaign->addPriceTier(new PriceTier($campaign, $threshold, $price));
        }

        $this->em->persist($supplier);
        $this->em->persist($category);
        $this->em->persist($product);
        $this->em->persist($campaign);
        $this->em->persist((new ProductImage($product, '/img/seconda.jpg'))->setPosition(1));
        $this->em->persist((new ProductImage($product, '/img/principale.jpg'))->setIsMain(true));
        $this->em->flush();

        return $campaign;
    }

    private function participate(Campaign $campaign, string $local, ParticipationStatus $status): void
    {
        $user = new User('Mario', $local.'@catalog-test.example');
        $this->em->persist($user);
        $this->em->persist((new Participation($campaign, $user, 1))->setStatus($status));
        $this->em->flush();
    }

    /**
     * @param list<array<string, mixed>> $items
     *
     * @return array<string, mixed>
     */
    private function findById(array $items, string $id): array
    {
        foreach ($items as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        self::fail('Campaign '.$id.' not found in the list.');
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
