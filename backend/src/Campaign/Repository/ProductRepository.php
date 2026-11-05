<?php

namespace App\Campaign\Repository;

use App\Campaign\Entity\Product;
use App\Campaign\Enum\ProductStatus;
use App\Supplier\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Prodotti attivi di tutti i fornitori, per fornitore e nome (legacy: catalogo_prodotti).
     *
     * @return list<Product>
     */
    public function findActive(?Supplier $supplier = null): array
    {
        $query = $this->createQueryBuilder('p')
            ->addSelect('s', 'c')
            ->join('p.supplier', 's')
            ->leftJoin('p.category', 'c')
            ->where('p.status = :status')
            ->setParameter('status', ProductStatus::Attivo)
            ->orderBy('s.name', 'ASC')
            ->addOrderBy('p.name', 'ASC');
        if (null !== $supplier) {
            $query->andWhere('p.supplier = :supplier')->setParameter('supplier', $supplier->getId(), 'uuid');
        }

        /** @var list<Product> $found */
        $found = $query->getQuery()->getResult();

        return $found;
    }

    /**
     * @return Product[]
     */
    public function findActiveBySupplier(Supplier $supplier): array
    {
        return $this->findBy(['supplier' => $supplier, 'status' => ProductStatus::Attivo], ['name' => 'ASC']);
    }
}
