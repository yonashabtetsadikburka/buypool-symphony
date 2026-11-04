<?php

namespace App\Supplier\Service;

use App\Delivery\Repository\PickupPointRepository;
use App\Identity\Entity\User;
use App\Pricing\PricingCalculator;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use App\Supplier\Entity\Supplier;
use App\Supplier\Entity\SupplierOrder;
use App\Supplier\Enum\SupplierOrderStatus;
use App\Supplier\Repository\SupplierOrderRepository;
use App\Supplier\Repository\SupplierRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Ordini visti e fatti avanzare dal fornitore (legacy: fornitore_ordini_*, endpoints/fornitore_ordini.php).
 * Il fornitore vede e tocca solo i propri ordini (404 per gli altri: non si rivela se esistono) e non vede mai dati dei
 * clienti, commissione né prezzi pagati. Passi: Da confermare → In preparazione → Spedito. "Completato" lo segna l'admin
 * con "Merce arrivata". Il fornitore non può rifiutare l'ordine.
 */
final class SupplierFulfillmentService
{
    public const CARRIERS = ['BRT', 'GLS', 'SDA', 'DHL', 'UPS', 'FedEx', 'Poste Italiane', 'Altro'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly SupplierRepository $suppliers,
        private readonly SupplierOrderRepository $orders,
        private readonly PricingCalculator $pricing,
        private readonly PickupPointRepository $pickupPoints,
        #[Autowire('%app.supplier.confirmation_hours%')]
        private readonly int $confirmationHours,
    ) {
    }

    /**
     * @return array{contatori: array<string, int>, ordini: list<array<string, mixed>>, consegna: ?array<string, ?string>, conferma_ore: int}
     */
    public function list(User $user): array
    {
        $supplier = $this->supplierOf($user);
        $rows = [];
        $counters = ['da_confermare' => 0, 'in_preparazione' => 0, 'spediti' => 0, 'completati' => 0];
        foreach ($this->orders->findBy(['supplier' => $supplier], ['orderedAt' => 'DESC', 'id' => 'DESC']) as $order) {
            $stage = self::stage($order->getStatus());
            if (null === $stage) {
                continue; // da negoziare, annullato: non mostrabili al fornitore
            }
            ++$counters[self::TAB[$stage]];
            $rows[] = $this->row($order, $stage);
        }

        $point = $this->pickupPoints->findDefault();

        return [
            'contatori' => $counters,
            'ordini' => $rows,
            'consegna' => null === $point ? null : ['nome' => $point->getName(), 'indirizzo' => $point->getAddress(), 'citta' => $point->getCity(), 'orari' => $point->getOpeningHours()],
            'conferma_ore' => $this->confirmationHours,
        ];
    }

    /**
     * "Conferma ordine": Da confermare → In preparazione.
     *
     * @return array{id: string, stato_fornitore: string}
     */
    public function confirm(User $user, string $orderId): array
    {
        $supplier = $this->supplierOf($user);

        return $this->transaction->run(function () use ($supplier, $orderId): array {
            $order = $this->lock($supplier, $orderId);
            if (SupplierOrderStatus::Inviato !== $order->getStatus()) {
                throw new ApiError('STATO_NON_VALIDO', "L'ordine non e' da confermare", 409);
            }
            $order->setStatus(SupplierOrderStatus::InPreparazione)->setConfirmedAt(new \DateTimeImmutable());

            return ['id' => $order->getId()->toRfc4122(), 'stato_fornitore' => 'in_preparazione'];
        });
    }

    /**
     * "Segna come spedito": In preparazione → Spedito, con corriere, tracking e data di spedizione.
     *
     * @param array<string, mixed> $input corriere, corriere_altro?, tracking, data_spedizione (Y-m-d)
     *
     * @return array{id: string, stato_fornitore: string, corriere: string, tracking: string, data_spedizione: string}
     */
    public function ship(User $user, string $orderId, array $input, ?\DateTimeImmutable $today = null): array
    {
        $supplier = $this->supplierOf($user);
        $today ??= new \DateTimeImmutable('today');

        return $this->transaction->run(function () use ($supplier, $orderId, $input, $today): array {
            $order = $this->lock($supplier, $orderId);
            if (!\in_array($order->getStatus(), [SupplierOrderStatus::Ricevuto, SupplierOrderStatus::InPreparazione], true)) {
                throw new ApiError('STATO_NON_VALIDO', "L'ordine non e' in preparazione", 409);
            }
            $shipment = $this->validShipment($input, $order->getOrderedAt(), $today);
            $order->setShipment($shipment['corriere'], $shipment['tracking'], new \DateTimeImmutable($shipment['data_spedizione']))
                ->setStatus(SupplierOrderStatus::Spedito);

            return ['id' => $order->getId()->toRfc4122(), 'stato_fornitore' => 'spedito'] + $shipment;
        });
    }

    /** Fase vista dal fornitore, o null se l'ordine non è mostrabile. */
    public static function stage(SupplierOrderStatus $status): ?string
    {
        return match ($status) {
            SupplierOrderStatus::Inviato => 'da_confermare',
            SupplierOrderStatus::Ricevuto, SupplierOrderStatus::InPreparazione => 'in_preparazione',
            SupplierOrderStatus::Spedito => 'spedito',
            SupplierOrderStatus::Evaso, SupplierOrderStatus::Consegnato => 'completato',
            default => null,
        };
    }

    private const LABEL = ['da_confermare' => 'Da confermare', 'in_preparazione' => 'In preparazione', 'spedito' => 'Spedito', 'completato' => 'Completato'];
    private const TAB = ['da_confermare' => 'da_confermare', 'in_preparazione' => 'in_preparazione', 'spedito' => 'spediti', 'completato' => 'completati'];
    private const ACTIONS = ['da_confermare' => ['conferma'], 'in_preparazione' => ['spedisci']];

    /**
     * Importo = unità ordinate × prezzo finale del fornitore (mai commissione); nessun dato dei clienti.
     *
     * @return array<string, mixed>
     */
    private function row(SupplierOrder $order, string $stage): array
    {
        $campaign = $order->getCampaign();
        $quantity = $order->getOrderedQuantity();
        $unit = $this->pricing->balancePrice($campaign->getFinalPriceCents(), $campaign->getPriceTiers(), $quantity, $campaign->getCurrentPriceCents());
        $ordered = $order->getOrderedAt();

        return [
            'id' => $order->getId()->toRfc4122(),
            'id_colletta' => $campaign->getId()->toRfc4122(),
            'numero' => $order->getOrderNumber(),
            'prodotto' => $campaign->getProduct()->getName(),
            'quantita_ordinata' => $quantity,
            'prezzo_unitario_cents' => $unit,
            'importo_cents' => $quantity * $unit,
            'stato_fornitore' => $stage,
            'stato_etichetta' => self::LABEL[$stage],
            'scheda' => self::TAB[$stage],
            'azioni' => self::ACTIONS[$stage] ?? [],
            'data_ordine' => $ordered?->format('Y-m-d H:i:s'),
            'scadenza_conferma' => 'da_confermare' === $stage && null !== $ordered ? $ordered->modify('+'.$this->confirmationHours.' hours')->format('Y-m-d H:i:s') : null,
            'confermato_il' => $order->getConfirmedAt()?->format('Y-m-d H:i:s'),
            'corriere' => $order->getCarrier(),
            'tracking' => $order->getTrackingNumber(),
            'data_spedizione' => $order->getShippedOn()?->format('Y-m-d'),
            'data_arrivo' => 'completato' === $stage ? $order->getDeliveredAt()?->format('Y-m-d H:i:s') : null,
        ];
    }

    private function supplierOf(User $user): Supplier
    {
        $supplier = $this->suppliers->findOneBy(['user' => $user]);
        if (!$supplier instanceof Supplier) {
            throw new ApiError('FORNITORE_NON_COLLEGATO', 'Il tuo account non e\' collegato a nessun fornitore', 403);
        }

        return $supplier;
    }

    /** Legge e blocca un ordine dell'azienda; 404 se non esiste o è di un altro (stesso errore). */
    private function lock(Supplier $supplier, string $orderId): SupplierOrder
    {
        $notFound = new ApiError('ORDINE_INESISTENTE', 'Ordine non trovato', 404);
        if (!Uuid::isValid($orderId)) {
            throw $notFound;
        }
        $order = $this->em->find(SupplierOrder::class, Uuid::fromString($orderId), LockMode::PESSIMISTIC_WRITE);
        if (!$order instanceof SupplierOrder || !$order->getSupplier()->getId()->equals($supplier->getId())) {
            throw $notFound;
        }
        $this->em->refresh($order);

        return $order;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{corriere: string, tracking: string, data_spedizione: string}
     */
    private function validShipment(array $input, ?\DateTimeImmutable $orderedAt, \DateTimeImmutable $today): array
    {
        $errors = [];
        $carrier = $this->text($input['corriere'] ?? null);
        if (!\in_array($carrier, self::CARRIERS, true)) {
            $errors['corriere'] = 'Scegli il corriere';
        } elseif ('Altro' === $carrier) {
            $other = trim((string) preg_replace('/\s+/u', ' ', $this->text($input['corriere_altro'] ?? null)));
            if (mb_strlen($other) < 2 || mb_strlen($other) > 40 || 1 === preg_match('/[<>]/', $other)) {
                $errors['corriere_altro'] = 'Scrivi il nome del corriere (da 2 a 40 caratteri)';
            } else {
                $carrier = $other;
            }
        }

        $tracking = $this->text($input['tracking'] ?? null);
        if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9 .\/-]{3,38}[A-Za-z0-9]$/', $tracking)) {
            $errors['tracking'] = 'Inserisci il numero di tracking (da 5 a 40 tra lettere, numeri e trattini)';
        }

        $date = $this->text($input['data_spedizione'] ?? null);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (false === $parsed || $parsed->format('Y-m-d') !== $date) {
            $errors['data_spedizione'] = 'Indica la data di spedizione';
        } elseif ($date > $today->format('Y-m-d')) {
            $errors['data_spedizione'] = "La data di spedizione non puo' essere futura";
        } elseif (null !== $orderedAt && $date < $orderedAt->format('Y-m-d')) {
            $errors['data_spedizione'] = "La data di spedizione non puo' essere precedente all'ordine";
        }

        if ([] !== $errors) {
            throw new ApiError('DATI_NON_VALIDI', 'Controlla i campi evidenziati', 422, $errors);
        }

        return ['corriere' => $carrier, 'tracking' => $tracking, 'data_spedizione' => $date];
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
