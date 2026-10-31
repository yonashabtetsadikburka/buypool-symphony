<?php

namespace App\Campaign\Repository;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\ProductImage;
use App\Campaign\Enum\CampaignStatus;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use App\Supplier\Entity\Supplier;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Campaign>
 */
class CampaignRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campaign::class);
    }

    /**
     * @return Campaign[]
     */
    public function findByStatus(CampaignStatus $status): array
    {
        return $this->findBy(['status' => $status], ['deadline' => 'ASC']);
    }

    /**
     * Gruppi per la vetrina pubblica, per scadenza crescente. Prodotto, fornitore, categoria
     * e punto di ritiro arrivano nella stessa query.
     *
     * @return Campaign[]
     */
    public function findForCatalog(): array
    {
        return $this->catalogQuery()
            ->orderBy('c.deadline', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForCatalog(Uuid $id): ?Campaign
    {
        return $this->catalogQuery()
            ->andWhere('c.id = :id')
            ->setParameter('id', $id, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Scaglioni per soglia crescente, raggruppati per gruppo.
     *
     * @param Campaign[] $campaigns
     *
     * @return array<string, list<PriceTier>> chiave: id del gruppo (RFC 4122)
     */
    public function findTiersByCampaign(array $campaigns): array
    {
        if ([] === $campaigns) {
            return [];
        }

        /** @var PriceTier[] $tiers */
        $tiers = $this->getEntityManager()->createQueryBuilder()
            ->select('t')
            ->from(PriceTier::class, 't')
            ->where('IDENTITY(t.campaign) IN (:ids)')
            ->setParameter('ids', self::binaryIds(array_map(static fn (Campaign $c) => $c->getId(), $campaigns)), ArrayParameterType::BINARY)
            ->orderBy('t.threshold', 'ASC')
            ->getQuery()
            ->getResult();

        $byCampaign = [];
        foreach ($tiers as $tier) {
            $byCampaign[$tier->getCampaign()->getId()->toRfc4122()][] = $tier;
        }

        return $byCampaign;
    }

    /**
     * Immagini dei prodotti dei gruppi: prima la principale, poi per posizione.
     *
     * @param Campaign[] $campaigns
     *
     * @return array<string, list<ProductImage>> chiave: id del prodotto (RFC 4122)
     */
    public function findImagesByProduct(array $campaigns): array
    {
        if ([] === $campaigns) {
            return [];
        }

        /** @var ProductImage[] $images */
        $images = $this->getEntityManager()->createQueryBuilder()
            ->select('i')
            ->from(ProductImage::class, 'i')
            ->where('IDENTITY(i.product) IN (:ids)')
            ->setParameter('ids', self::binaryIds(array_map(static fn (Campaign $c) => $c->getProduct()->getId(), $campaigns)), ArrayParameterType::BINARY)
            ->orderBy('i.isMain', 'DESC')
            ->addOrderBy('i.position', 'ASC')
            ->getQuery()
            ->getResult();

        $byProduct = [];
        foreach ($images as $image) {
            $byProduct[$image->getProduct()->getId()->toRfc4122()][] = $image;
        }

        return $byProduct;
    }

    /**
     * Clienti distinti con un'adesione attiva (esclusi annullate e rimborsate).
     *
     * @param Campaign[] $campaigns
     *
     * @return array<string, int> chiave: id del gruppo (RFC 4122)
     */
    public function countParticipantsByCampaign(array $campaigns): array
    {
        if ([] === $campaigns) {
            return [];
        }

        /** @var list<array{campaignId: Uuid, participants: numeric-string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('c.id AS campaignId', 'COUNT(DISTINCT p.user) AS participants')
            ->from(Participation::class, 'p')
            ->join('p.campaign', 'c')
            ->where('IDENTITY(p.campaign) IN (:ids)')
            ->andWhere('p.status NOT IN (:inactive)')
            ->setParameter('ids', self::binaryIds(array_map(static fn (Campaign $c) => $c->getId(), $campaigns)), ArrayParameterType::BINARY)
            ->setParameter('inactive', [ParticipationStatus::Annullata, ParticipationStatus::Rimborsata])
            ->groupBy('c.id')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['campaignId']->toRfc4122()] = (int) $row['participants'];
        }

        return $counts;
    }

    /** Gruppi (di qualunque stato) di un fornitore. */
    public function countBySupplier(Supplier $supplier): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->join('c.product', 'p')
            ->where('p.supplier = :supplier')
            ->setParameter('supplier', $supplier->getId(), 'uuid')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function binaryIds(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toBinary(), $ids);
    }

    private function catalogQuery(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->addSelect('p', 's', 'cat', 'pp')
            ->join('c.product', 'p')
            ->join('p.supplier', 's')
            ->leftJoin('p.category', 'cat')
            ->leftJoin('c.pickupPoint', 'pp');
    }
}
