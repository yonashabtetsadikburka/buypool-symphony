<?php

namespace App\Catalog\Controller;

use App\Campaign\Entity\Product;
use App\Campaign\Entity\ProductImage;
use App\Campaign\Repository\CampaignRepository;
use App\Campaign\Repository\ProductRepository;
use App\Delivery\Repository\PickupPointRepository;
use App\Shared\Http\ApiError;
use App\Shared\Http\ApiResponse;
use App\Supplier\Entity\Supplier;
use App\Supplier\Repository\SupplierRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Cataloghi per il cliente collegato (US-21, legacy: endpoints/catalogo.php): fornitori pubblici, prodotti attivi e sedi
 * di ritiro. Sola lettura. Importi in centesimi.
 */
final class CatalogController
{
    #[Route('/api/catalog/suppliers', name: 'api_catalog_suppliers', methods: ['GET'])]
    public function suppliers(SupplierRepository $suppliers, CampaignRepository $campaigns): JsonResponse
    {
        return ApiResponse::ok(array_map(static fn (Supplier $supplier): array => [
            'id' => $supplier->getId()->toRfc4122(),
            'nome' => $supplier->getName(),
            'indirizzo' => $supplier->getAddress(),
            'descrizione' => $supplier->getDescription(),
            'logo_url' => $supplier->getLogoPath(),
            'sito_web' => $supplier->getWebsiteUrl(),
            'categoria' => null,
            'email_contatto' => $supplier->getContactEmail(),
            'telefono' => $supplier->getPhone(),
            'data_partnership' => $supplier->getPartneredAt()->format(\DATE_ATOM),
            'num_campagne' => $campaigns->countBySupplier($supplier),
        ], $suppliers->findPublic()));
    }

    #[Route('/api/catalog/products', name: 'api_catalog_products', methods: ['GET'])]
    public function products(Request $request, ProductRepository $products, EntityManagerInterface $em): JsonResponse
    {
        $supplier = null;
        $filter = $request->query->get('fornitore_id');
        if (null !== $filter) {
            if (!Uuid::isValid((string) $filter)) {
                throw new ApiError('CAMPO_NON_VALIDO', 'fornitore_id non valido');
            }
            $supplier = $em->find(Supplier::class, Uuid::fromString($filter));
            if (null === $supplier) {
                return ApiResponse::ok(['prodotti' => []]);
            }
        }

        $found = $products->findActive($supplier);
        $images = $this->mainImages($em, $found);

        return ApiResponse::ok(['prodotti' => array_map(static fn (Product $product): array => [
            'id' => $product->getId()->toRfc4122(),
            'nome' => $product->getName(),
            'descrizione' => $product->getDescription(),
            'prezzo_unitario_cents' => $product->getUnitPriceCents(),
            'prezzo_base_cents' => $product->getBasePriceCents() ?? $product->getUnitPriceCents(),
            'quantita_minima' => $product->getMinQuantity(),
            'id_fornitore' => $product->getSupplier()->getId()->toRfc4122(),
            'fornitore' => $product->getSupplier()->getName(),
            'categoria_id' => $product->getCategory()?->getId()->toRfc4122(),
            'categoria' => $product->getCategory()?->getName(),
            'immagine' => $images[$product->getId()->toRfc4122()] ?? null,
        ], $found)]);
    }

    #[Route('/api/catalog/pickup-points', name: 'api_catalog_pickup_points', methods: ['GET'])]
    public function pickupPoints(PickupPointRepository $pickupPoints): JsonResponse
    {
        return ApiResponse::ok(array_map(static fn ($point): array => [
            'id_sede' => $point->getId()->toRfc4122(),
            'nome' => $point->getName(),
            'indirizzo' => $point->getAddress(),
            'citta' => $point->getCity(),
            'telefono' => $point->getPhone(),
            'orari' => $point->getOpeningHours(),
        ], $pickupPoints->findAllOrderedByCity()));
    }

    /**
     * Immagine principale di ogni prodotto (la principale, altrimenti la prima per posizione), in una sola query.
     *
     * @param list<Product> $products
     *
     * @return array<string, string> chiave: id del prodotto (RFC 4122)
     */
    private function mainImages(EntityManagerInterface $em, array $products): array
    {
        if ([] === $products) {
            return [];
        }

        /** @var list<ProductImage> $images */
        $images = $em->createQueryBuilder()
            ->select('i')
            ->from(ProductImage::class, 'i')
            ->where('IDENTITY(i.product) IN (:products)')
            ->setParameter('products', array_map(static fn (Product $product): string => $product->getId()->toBinary(), $products), ArrayParameterType::BINARY)
            ->orderBy('i.isMain', 'DESC')
            ->addOrderBy('i.position', 'ASC')
            ->getQuery()
            ->getResult();

        $main = [];
        foreach ($images as $image) {
            $main[$image->getProduct()->getId()->toRfc4122()] ??= $image->getUrl();
        }

        return $main;
    }
}
