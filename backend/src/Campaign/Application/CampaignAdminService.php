<?php

namespace App\Campaign\Application;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\Category;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Entity\Product;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Repository\CampaignRepository;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Repository\PickupPointRepository;
use App\Identity\Entity\User;
use App\Pricing\PricingCalculator;
use App\Shared\Http\ApiError;
use App\Supplier\Entity\Supplier;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Creazione, modifica ed eliminazione dei gruppi da parte dell'admin (legacy: campagne_crea,
 * campagne_modifica, campagne_elimina, valida_scaglioni, valida_dati_gruppo).
 *
 * Gli importi arrivano in centesimi (interi). Il minimo del gruppo è la soglia del primo scaglione,
 * se ci sono scaglioni (legacy: minimo_da_scaglioni).
 */
final class CampaignAdminService
{
    private const MAX_TIERS = 10;
    private const MAX_PRODUCT_NAME = 80;
    private const MAX_DESCRIPTION = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CampaignRepository $campaigns,
        private readonly PickupPointRepository $pickupPoints,
        private readonly PricingCalculator $pricing,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{id: string, id_prodotto: string}
     */
    public function create(User $admin, array $data): array
    {
        $now = new \DateTimeImmutable();
        $deadline = $this->parseDeadline($data['scadenza'] ?? null, 'scadenza', $now);

        return $this->transactional(function () use ($admin, $data, $deadline, $now): array {
            $product = $this->findProduct($data['prodotto_id'] ?? null);
            $newProduct = null;   // creato qui ma salvato solo dopo tutte le validazioni
            if (null === $product) {
                $name = $this->productName($data['nome'] ?? null, 'NOME_MANCANTE');
                $supplier = $this->findSupplier($data['id_fornitore'] ?? null);
                $currentPrice = $this->price($data['prezzo_corrente_cents'] ?? null, 'Prezzo scontato non valido');
                $basePrice = isset($data['prezzo_base_cents']) ? $this->price($data['prezzo_base_cents'], 'Prezzo di listino non valido') : $currentPrice;
                $moq = $this->moq($data['quantita_minima'] ?? 1);
                $product = $newProduct = new Product($supplier, $name, $currentPrice);
            } else {
                $currentPrice = isset($data['prezzo_corrente_cents']) ? $this->price($data['prezzo_corrente_cents'], 'Prezzi non validi') : $product->getUnitPriceCents();
                $basePrice = isset($data['prezzo_base_cents']) ? $this->price($data['prezzo_base_cents'], 'Prezzi non validi') : ($product->getBasePriceCents() ?? $currentPrice);
                $moq = $this->moq($data['quantita_minima'] ?? $product->getMinQuantity());
            }

            $commission = $this->commission($data['percentuale_commissione'] ?? 10);
            $tiers = isset($data['scaglioni']) ? $this->parseTiers($data['scaglioni']) : [];
            $moq = [] !== $tiers ? $tiers[0]['soglia'] : $moq;
            $pickupPoint = $this->resolvePickupPoint($data['sede_id'] ?? null);

            $this->assertValidData($tiers, $moq, $basePrice, $currentPrice, $deadline, $pickupPoint, $now);

            if (null !== $newProduct) {
                $newProduct->setBasePriceCents($basePrice)->setMinQuantity($moq);
                $newProduct->setDescription(isset($data['descrizione']) ? trim((string) $data['descrizione']) : null);
                $newProduct->setCategory($this->findCategory($data['id_categoria'] ?? null));
                $this->em->persist($newProduct);
            }

            $campaign = new Campaign($product, $moq, $deadline, $basePrice, $currentPrice);
            $campaign->setCommissionRate($commission)->setOpenedBy($admin)->setReferrer($admin)->setPickupPoint($pickupPoint);
            foreach ($tiers as $tier) {
                $campaign->addPriceTier(new PriceTier($campaign, $tier['soglia'], $tier['prezzo']));
            }
            $this->em->persist($campaign);

            return ['id' => $campaign->getId()->toRfc4122(), 'id_prodotto' => $product->getId()->toRfc4122()];
        });
    }

    /**
     * Modifica parziale: contano solo i campi presenti e diversi dal valore attuale
     * (il form del legacy li rimandava tutti).
     *
     * @param array<string, mixed> $data
     *
     * @return list<string> campi cambiati
     */
    public function update(string $id, array $data): array
    {
        $now = new \DateTimeImmutable();

        return $this->transactional(function () use ($id, $data, $now): array {
            $campaign = $this->lock($id);
            if (\in_array($campaign->getStatus(), [CampaignStatus::OrdineFornitore, CampaignStatus::Consegnata], true)) {
                throw new ApiError('CAMPAGNA_NON_MODIFICABILE', "Gruppo non modificabile: ordine già partito", 409);
            }

            $product = $campaign->getProduct();
            $currentTiers = $this->sortedTiers($campaign);
            $currentTierData = array_map(static fn (PriceTier $t): array => ['soglia' => $t->getThreshold(), 'prezzo' => $t->getPriceCents()], $currentTiers);
            $actions = $this->actionsFor($campaign, $now);
            $changed = [];
            $needs = [];   // permessi richiesti dai campi davvero cambiati

            $newTiers = null;
            if (\array_key_exists('scaglioni', $data) && null !== $data['scaglioni']) {
                $parsed = $this->parseTiers($data['scaglioni']);
                if ($parsed !== $currentTierData) {
                    $newTiers = $parsed;
                    $needs['everything'] = true;
                }
            }

            $hasTiers = [] !== ($newTiers ?? $currentTierData);
            $newMoq = isset($data['quantita_minima']) ? $this->moq($data['quantita_minima']) : null;
            if (null !== $newMoq && !$hasTiers && $newMoq !== $campaign->getMoq()) {
                $needs['everything'] = true;
            }
            $newCurrent = isset($data['prezzo_corrente_cents']) ? $this->price($data['prezzo_corrente_cents'], 'Prezzo scontato non valido') : null;
            if (null !== $newCurrent && $newCurrent !== $campaign->getCurrentPriceCents()) {
                $needs['everything'] = true;
            }
            $newBase = isset($data['prezzo_base_cents']) ? $this->price($data['prezzo_base_cents'], 'Prezzo di listino non valido') : null;
            if (null !== $newBase && $newBase !== $campaign->getBasePriceCents()) {
                $needs['everything'] = true;
            }
            $newCommission = isset($data['percentuale_commissione']) ? $this->commission($data['percentuale_commissione']) : null;
            if (null !== $newCommission && (float) $newCommission !== (float) $campaign->getCommissionRate()) {
                $needs['everything'] = true;
            }

            $newName = null;
            if (isset($data['nome']) && trim((string) $data['nome']) !== $product->getName()) {
                $newName = $this->productName($data['nome'], 'NOME_NON_VALIDO');
                $needs['texts'] = true;
            }
            $newDescription = null;
            if (isset($data['descrizione']) && trim((string) $data['descrizione']) !== (string) $product->getDescription()) {
                $newDescription = trim((string) $data['descrizione']);
                if (mb_strlen($newDescription) > self::MAX_DESCRIPTION) {
                    throw new ApiError('DESCRIZIONE_TROPPO_LUNGA', "La descrizione può avere al massimo 2000 caratteri", 422);
                }
                $needs['texts'] = true;
            }

            $newDeadline = null;
            if (isset($data['data_limite'])) {
                $candidate = $this->parseDeadline($data['data_limite'], 'data_limite', $now, 'SCADENZA_NON_VALIDA');
                if ($candidate->format('Y-m-d H:i') !== $campaign->getDeadline()->format('Y-m-d H:i')) {
                    if (!$actions->canEditEverything && $candidate <= $campaign->getDeadline()) {
                        throw new ApiError('SCADENZA_NON_ACCORCIABILE', "Con adesioni la scadenza si può solo prolungare, mai anticipare", 409);
                    }
                    $newDeadline = $candidate;
                    $needs['extend'] = true;
                }
            }

            if (isset($needs['everything']) && !$actions->canEditEverything) {
                throw new ApiError('AZIONE_NON_CONSENTITA', $this->lockedMessage($actions), 409);
            }
            if ((isset($needs['texts']) && !$actions->canEditTexts) || (isset($needs['extend']) && !$actions->canExtendDeadline)) {
                throw new ApiError('AZIONE_NON_CONSENTITA', $this->notAllowedMessage($actions), 409);
            }

            if (isset($needs['everything'])) {
                $finalTiers = $newTiers ?? $currentTierData;
                $finalMoq = [] !== $finalTiers ? $finalTiers[0]['soglia'] : ($newMoq ?? $campaign->getMoq());
                $finalBase = $newBase ?? $campaign->getBasePriceCents();
                $finalCurrent = $newCurrent ?? $campaign->getCurrentPriceCents();
                $finalDeadline = $newDeadline ?? $campaign->getDeadline();
                $this->assertValidData($finalTiers, $finalMoq, $finalBase, $finalCurrent, $finalDeadline, $campaign->getPickupPoint() ?? $this->pickupPoints->findDefault(), $now);

                if ($finalMoq !== $campaign->getMoq()) {
                    $campaign->setMoq($finalMoq);
                    $changed[] = 'quantita_minima';
                }
                if (null !== $newCurrent && $newCurrent !== $campaign->getCurrentPriceCents()) {
                    $campaign->setCurrentPriceCents($newCurrent);
                    $changed[] = 'prezzo_corrente_cents';
                }
                if (null !== $newBase && $newBase !== $campaign->getBasePriceCents()) {
                    $campaign->setBasePriceCents($newBase);
                    $changed[] = 'prezzo_base_cents';
                }
                if (null !== $newCommission && (float) $newCommission !== (float) $campaign->getCommissionRate()) {
                    $campaign->setCommissionRate($newCommission);
                    $changed[] = 'percentuale_commissione';
                }
            }

            if (null !== $newDeadline) {
                $campaign->setDeadline($newDeadline);
                // Un gruppo ancora aperto non può avere una chiusura registrata (dato incoerente nel legacy).
                $campaign->setClosedAt(null);
                $changed[] = 'data_limite';
            }
            if (null !== $newName) {
                $product->setName($newName);
                $changed[] = 'nome';
            }
            if (null !== $newDescription) {
                $product->setDescription($newDescription);
                $changed[] = 'descrizione';
            }

            if (null !== $newTiers) {
                // Prima si tolgono i vecchi scaglioni: la coppia (gruppo, soglia) è unica.
                foreach ($currentTiers as $tier) {
                    $campaign->removePriceTier($tier);
                }
                $this->em->flush();
                foreach ($newTiers as $tier) {
                    $campaign->addPriceTier(new PriceTier($campaign, $tier['soglia'], $tier['prezzo']));
                }
                $changed[] = 'scaglioni';
            }

            return $changed;
        });
    }

    /**
     * Elimina un gruppo: solo se aperto e senza adesioni vive, e mai se esistono pagamenti.
     */
    public function delete(string $id): void
    {
        $now = new \DateTimeImmutable();

        $this->transactional(function () use ($id, $now): void {
            $campaign = $this->lock($id);
            $actions = $this->actionsFor($campaign, $now);
            if (!$actions->canDelete) {
                throw new ApiError('AZIONE_NON_CONSENTITA', $this->notAllowedMessage($actions), 409);
            }
            if ($this->campaigns->countPayments($campaign) > 0) {
                throw new ApiError('HA_PAGAMENTI', "Impossibile eliminare: esistono pagamenti registrati. Con adesioni si annulla il gruppo e si rimborsa", 409);
            }

            $this->em->remove($campaign);
        });
    }

    /**
     * Transazione con salvataggio finale. Non usa wrapInTransaction(): in caso d'errore quello chiude
     * l'EntityManager, mentre qui l'errore di validazione è una normale risposta 4xx.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transactional(callable $work): mixed
    {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $result = $work();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    private function lock(string $id): Campaign
    {
        if (!Uuid::isValid($id)) {
            throw new ApiError('CAMPAGNA_INESISTENTE', 'Gruppo non trovato', 404);
        }
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE);
        if (!$campaign instanceof Campaign) {
            throw new ApiError('CAMPAGNA_INESISTENTE', 'Gruppo non trovato', 404);
        }

        return $campaign;
    }

    private function actionsFor(Campaign $campaign, \DateTimeImmutable $now): CampaignActions
    {
        $tiers = $this->sortedTiers($campaign);

        return CampaignActions::resolve(
            $campaign->getStatus(),
            $campaign->getDeadline(),
            $campaign->getCurrentQuantity() >= $this->pricing->minimumQuantity($tiers, $campaign->getMoq()),
            null !== $campaign->getClosedAt(),
            $this->campaigns->countLiveParticipations($campaign),
            $now,
        );
    }

    /** @return list<PriceTier> */
    private function sortedTiers(Campaign $campaign): array
    {
        $tiers = $campaign->getPriceTiers()->toArray();
        usort($tiers, static fn (PriceTier $a, PriceTier $b): int => $a->getThreshold() <=> $b->getThreshold());

        return $tiers;
    }

    private function lockedMessage(CampaignActions $actions): string
    {
        return CampaignActions::BLOCCATO === $actions->situation
            ? 'Il gruppo non è più modificabile'
            : 'Minimo, scaglioni, prezzi e commissione sono bloccati: il gruppo ha già delle adesioni o è '.mb_strtolower($actions->label());
    }

    private function notAllowedMessage(CampaignActions $actions): string
    {
        return 'Azione non consentita: il gruppo è "'.$actions->label().'"';
    }

    private function parseDeadline(mixed $value, string $field, \DateTimeImmutable $now, string $pastCode = 'DATA_NEL_PASSATO'): \DateTimeImmutable
    {
        if (!\is_string($value) || '' === trim($value)) {
            throw new ApiError('DATA_NON_VALIDA', 'Formato '.str_replace('_', ' ', $field).' non valido');
        }
        try {
            $date = new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            throw new ApiError('DATA_NON_VALIDA', 'Formato '.str_replace('_', ' ', $field).' non valido');
        }
        if ($date <= $now) {
            throw new ApiError($pastCode, 'La scadenza deve essere futura');
        }

        return $date;
    }

    private function price(mixed $value, string $message): int
    {
        if (!\is_int($value) || $value <= 0) {
            throw new ApiError('PREZZO_NON_VALIDO', $message);
        }

        return $value;
    }

    private function moq(mixed $value): int
    {
        if (!\is_int($value) || $value < 1) {
            throw new ApiError('MOQ_NON_VALIDO', 'Quantità minima deve essere almeno 1');
        }

        return $value;
    }

    private function commission(mixed $value): string
    {
        if ((!\is_int($value) && !\is_float($value)) || $value < 0 || $value > 100) {
            throw new ApiError('COMMISSIONE_NON_VALIDA', 'Commissione tra 0 e 100');
        }

        return number_format(round((float) $value, 2), 2, '.', '');
    }

    private function productName(mixed $value, string $missingCode): string
    {
        $name = \is_string($value) ? trim($value) : '';
        if ('' === $name) {
            throw new ApiError($missingCode, 'Nome prodotto obbligatorio');
        }
        if (mb_strlen($name) > self::MAX_PRODUCT_NAME) {
            throw new ApiError('NOME_TROPPO_LUNGO', 'Massimo 80 caratteri');
        }

        return $name;
    }

    private function findProduct(mixed $id): ?Product
    {
        if (null === $id || '' === $id) {
            return null;
        }
        $product = \is_string($id) && Uuid::isValid($id) ? $this->em->find(Product::class, Uuid::fromString($id)) : null;
        if (!$product instanceof Product) {
            throw new ApiError('PRODOTTO_INESISTENTE', 'Prodotto non trovato', 404);
        }

        return $product;
    }

    private function findSupplier(mixed $id): Supplier
    {
        if (null === $id || '' === $id) {
            throw new ApiError('FORNITORE_MANCANTE', 'Fornitore obbligatorio');
        }
        $supplier = \is_string($id) && Uuid::isValid($id) ? $this->em->find(Supplier::class, Uuid::fromString($id)) : null;
        if (!$supplier instanceof Supplier) {
            throw new ApiError('FORNITORE_INESISTENTE', 'Fornitore non trovato', 404);
        }

        return $supplier;
    }

    private function findCategory(mixed $id): ?Category
    {
        if (null === $id || '' === $id) {
            return null;
        }
        $category = \is_string($id) && Uuid::isValid($id) ? $this->em->find(Category::class, Uuid::fromString($id)) : null;
        if (!$category instanceof Category) {
            throw new ApiError('CATEGORIA_INESISTENTE', 'Categoria non trovata', 404);
        }

        return $category;
    }

    private function resolvePickupPoint(mixed $id): ?PickupPoint
    {
        if (null === $id || '' === $id) {
            return $this->pickupPoints->findDefault();
        }
        $point = \is_string($id) && Uuid::isValid($id) ? $this->em->find(PickupPoint::class, Uuid::fromString($id)) : null;
        if (!$point instanceof PickupPoint) {
            throw new ApiError('SEDE_INESISTENTE', 'Sede di ritiro non trovata', 404);
        }

        return $point;
    }

    /**
     * Scaglioni [{soglia, prezzo_cents}] → lista ordinata per soglia (legacy: valida_scaglioni).
     *
     * @return list<array{soglia: int, prezzo: int}>
     */
    private function parseTiers(mixed $raw): array
    {
        if (!\is_array($raw) || !array_is_list($raw)) {
            throw new ApiError('SCAGLIONI_NON_VALIDI', 'Formato scaglioni non valido');
        }
        if (\count($raw) > self::MAX_TIERS) {
            throw new ApiError('SCAGLIONI_NON_VALIDI', 'Massimo 10 scaglioni');
        }

        $tiers = [];
        $seen = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                throw new ApiError('SCAGLIONI_NON_VALIDI', 'Formato scaglioni non valido');
            }
            $threshold = $item['soglia'] ?? null;
            $price = $item['prezzo_cents'] ?? null;
            if (!\is_int($threshold) || $threshold < 1) {
                throw new ApiError('SCAGLIONI_NON_VALIDI', 'Ogni soglia deve essere almeno 1');
            }
            if (!\is_int($price) || $price <= 0) {
                throw new ApiError('SCAGLIONI_NON_VALIDI', 'Ogni prezzo deve essere maggiore di 0');
            }
            if (isset($seen[$threshold])) {
                throw new ApiError('SCAGLIONI_NON_VALIDI', 'Soglie duplicate non ammesse');
            }
            $seen[$threshold] = true;
            $tiers[] = ['soglia' => $threshold, 'prezzo' => $price];
        }

        usort($tiers, static fn (array $a, array $b): int => $a['soglia'] <=> $b['soglia']);
        for ($i = 1, $n = \count($tiers); $i < $n; ++$i) {
            if ($tiers[$i]['prezzo'] >= $tiers[$i - 1]['prezzo']) {
                throw new ApiError('SCAGLIONI_NON_VALIDI', "I prezzi degli scaglioni devono diminuire al crescere della quantità");
            }
        }

        return $tiers;
    }

    /**
     * Regole di coerenza dei dati del gruppo (legacy: valida_dati_gruppo). Risponde 422 con gli errori per campo.
     *
     * @param list<array{soglia: int, prezzo: int}> $tiers
     */
    private function assertValidData(array $tiers, int $moq, int $basePrice, int $currentPrice, \DateTimeImmutable $deadline, ?PickupPoint $pickupPoint, \DateTimeImmutable $now): void
    {
        $errors = [];

        if ($moq < 1) {
            $errors['quantita_minima'] = 'Il minimo deve essere almeno 1';
        }

        if ($basePrice <= 0) {
            $errors['prezzo_base'] = "Il prezzo di listino non è valido";
        } else {
            $firstPrice = [] !== $tiers ? $tiers[0]['prezzo'] : $currentPrice;
            if ($firstPrice > 0 && ([] !== $tiers ? $basePrice <= $firstPrice : $basePrice < $firstPrice)) {
                $errors['prezzo_base'] = 'Il prezzo di listino deve essere maggiore del prezzo del primo scaglione';
            }
        }

        if ($deadline <= $now) {
            $errors['scadenza'] = 'La scadenza deve essere una data futura';
        }

        if (null === $pickupPoint) {
            $errors['id_sede'] = 'Manca la sede di ritiro';
        }

        if ([] !== $errors) {
            throw new ApiError('DATI_NON_VALIDI', (string) reset($errors), 422, $errors);
        }
    }
}
