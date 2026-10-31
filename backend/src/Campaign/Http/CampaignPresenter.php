<?php

namespace App\Campaign\Http;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\ProductImage;
use App\Campaign\Enum\CampaignDisplayStatus;
use App\Campaign\Repository\CampaignRepository;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Repository\PickupPointRepository;
use App\Pricing\PricingCalculator;
use App\Pricing\PricingSettings;
use Psr\Clock\ClockInterface;

/**
 * Forma JSON pubblica di un gruppo (vetrina). Nomi dei campi come nel legacy;
 * gli importi sono in centesimi (`*_cents`) invece che in euro con decimali.
 * Non contiene dati personali dei partecipanti, solo il loro numero.
 */
final class CampaignPresenter
{
    public function __construct(
        private readonly CampaignRepository $campaigns,
        private readonly PickupPointRepository $pickupPoints,
        private readonly PricingCalculator $pricing,
        private readonly PricingSettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @param Campaign[] $campaigns
     *
     * @return list<array<string, mixed>>
     */
    public function list(array $campaigns): array
    {
        $context = $this->loadContext($campaigns);

        return array_map(fn (Campaign $campaign): array => $this->card($campaign, $context), array_values($campaigns));
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(Campaign $campaign): array
    {
        $context = $this->loadContext([$campaign]);
        $supplier = $campaign->getProduct()->getSupplier();

        return $this->card($campaign, $context) + [
            'costo_spedizione_cents' => $this->settings->homeDeliveryCents,
            'fornitore_info' => [
                'nome' => $supplier->getName(),
                'data_partnership' => $supplier->getPartneredAt()->format(\DATE_ATOM),
                'num_campagne' => $this->campaigns->countBySupplier($supplier),
            ],
        ];
    }

    /**
     * @param Campaign[] $campaigns
     *
     * @return array{tiers: array<string, list<PriceTier>>, images: array<string, list<ProductImage>>, participants: array<string, int>, defaultPickupPoint: ?PickupPoint, now: \DateTimeImmutable}
     */
    private function loadContext(array $campaigns): array
    {
        return [
            'tiers' => $this->campaigns->findTiersByCampaign($campaigns),
            'images' => $this->campaigns->findImagesByProduct($campaigns),
            'participants' => $this->campaigns->countParticipantsByCampaign($campaigns),
            'defaultPickupPoint' => $this->pickupPoints->findDefault(),
            'now' => \DateTimeImmutable::createFromInterface($this->clock->now()),
        ];
    }

    /**
     * @param array{tiers: array<string, list<PriceTier>>, images: array<string, list<ProductImage>>, participants: array<string, int>, defaultPickupPoint: ?PickupPoint, now: \DateTimeImmutable} $context
     *
     * @return array<string, mixed>
     */
    private function card(Campaign $campaign, array $context): array
    {
        $id = $campaign->getId()->toRfc4122();
        $product = $campaign->getProduct();
        $supplier = $product->getSupplier();
        $category = $product->getCategory();
        $tiers = $context['tiers'][$id] ?? [];
        $images = $context['images'][$product->getId()->toRfc4122()] ?? [];
        // Il ritiro in sede è sempre disponibile: senza punto di ritiro proprio vale la sede predefinita.
        $pickupPoint = $campaign->getPickupPoint() ?? $context['defaultPickupPoint'];
        $minimum = $this->pricing->minimumQuantity($tiers, $campaign->getMoq());

        $imageList = array_map(static fn (ProductImage $image): array => [
            'id' => $image->getId()->toRfc4122(),
            'url' => $image->getUrl(),
            'principale' => $image->isMain() ? 1 : 0,
            'ordine' => $image->getPosition(),
        ], $images);

        return [
            'id' => $id,
            'stato' => $campaign->getStatus()->value,
            'stato_campagna' => CampaignDisplayStatus::resolve(
                $campaign->getStatus(),
                $campaign->getDeadline(),
                $campaign->getCurrentQuantity(),
                $minimum,
                $campaign->getClosedAt(),
                $context['now'],
            )->value,
            'data_inizio' => $campaign->getStartsAt()->format(\DATE_ATOM),
            'data_limite' => $campaign->getDeadline()->format(\DATE_ATOM),
            'quantita_minima' => $campaign->getMoq(),
            'quantita_attuale' => $campaign->getCurrentQuantity(),
            'soglia_raggiunta' => $campaign->getCurrentQuantity() >= $campaign->getMoq(),
            'prezzo_base_cents' => $campaign->getBasePriceCents(),
            'prezzo_corrente_cents' => $campaign->getCurrentPriceCents(),
            'percentuale_commissione' => (float) $campaign->getCommissionRate(),
            'prodotto' => $product->getName(),
            'descrizione_prodotto' => $product->getDescription(),
            'prezzo_unitario_cents' => $product->getUnitPriceCents(),
            'lotto_prodotto' => $product->getMinQuantity(),
            'id_categoria' => $category?->getId()->toRfc4122(),
            'categoria' => $category?->getName(),
            'immagine' => $imageList[0]['url'] ?? null,
            'immagini' => $imageList,
            'fornitore' => $supplier->getName(),
            'fornitore_id' => $supplier->getId()->toRfc4122(),
            'id_sede' => $pickupPoint?->getId()->toRfc4122(),
            'punto_ritiro' => $pickupPoint?->getName(),
            'sede_indirizzo' => $pickupPoint?->getAddress(),
            'citta' => $pickupPoint?->getCity(),
            'ritiro_disponibile' => null !== $pickupPoint,
            'spedizione_disponibile' => true,
            'partecipanti' => $context['participants'][$id] ?? 0,
            'scaglioni' => array_map(static fn (PriceTier $tier): array => [
                'soglia' => $tier->getThreshold(),
                'prezzo_cents' => $tier->getPriceCents(),
            ], $tiers),
        ];
    }
}
