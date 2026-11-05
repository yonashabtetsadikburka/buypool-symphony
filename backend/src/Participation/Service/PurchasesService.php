<?php

namespace App\Participation\Service;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Repository\CampaignRepository;
use App\Delivery\Entity\Delivery;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Entity\PickupToken;
use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Enum\DeliveryStatus;
use App\Delivery\Enum\PickupTokenStatus;
use App\Delivery\Repository\DeliveryRepository;
use App\Delivery\Repository\PickupPointRepository;
use App\Delivery\Repository\PickupTokenRepository;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Payment\Entity\Payment;
use App\Payment\Enum\PaymentStatus;
use App\Pricing\PricingCalculator;
use App\Pricing\PricingSettings;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * "I miei acquisti" (legacy: mie_acquisti + lib/acquisti.php): adesioni del cliente classificate in quattro schede
 * (in-corso, in-arrivo, conclusi, annullati) con la "vista" che dice a che punto è ciascuna. Sola lettura.
 * I numeri di prezzo e saldo vengono da PricingCalculator, le stesse formule della chiusura; importi in centesimi.
 */
final class PurchasesService
{
    public const TABS = ['in-corso', 'in-arrivo', 'conclusi', 'annullati'];
    public const SORTS = ['scadenza', 'recenti'];

    private const LABELS = [
        'in_corso' => 'Gruppo in corso',
        'attivo' => 'Gruppo attivo',
        'pagamento' => 'Pagamento non riuscito',
        'inviato' => 'Ordine inviato al fornitore',
        'pronto' => 'Pronto per il ritiro',
        'ritirato' => 'Ritirato',
        'rimborsato' => 'Rimborsato',
        'annullato' => 'Annullato',
    ];

    /** Passo del percorso a 6 puntini per ogni vista. */
    private const STEP = ['in_corso' => 3, 'attivo' => 3, 'pagamento' => 4, 'inviato' => 5, 'pronto' => 6, 'ritirato' => 6, 'rimborsato' => 0, 'annullato' => 0];

    public function __construct(
        private readonly ParticipationRepository $participations,
        private readonly CampaignRepository $campaigns,
        private readonly DeliveryRepository $deliveries,
        private readonly PickupTokenRepository $tokens,
        private readonly PickupPointRepository $pickupPoints,
        private readonly EntityManagerInterface $em,
        private readonly PricingCalculator $pricing,
        private readonly PricingSettings $settings,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return array{tab: string, sort: string, tab_predefinito: string, conteggi: array<string, int>, riepilogo: array{gruppi_in_corso: int, ritiri_pronti: int}, acquisti: list<array<string, mixed>>}
     */
    public function list(User $user, mixed $tab, mixed $sort, mixed $participationId = null): array
    {
        $rows = $this->rows($user);

        $counts = array_fill_keys(self::TABS, 0);
        $open = 0;
        $ready = 0;
        foreach ($rows as $row) {
            ++$counts[$row['tab']];
            $open += \in_array($row['vista'], ['in_corso', 'attivo'], true) ? 1 : 0;
            $ready += 'pronto' === $row['vista'] ? 1 : 0;
        }
        $default = 'in-corso';
        foreach (self::TABS as $candidate) {
            if ($counts[$candidate] > 0) {
                $default = $candidate;
                break;
            }
        }

        $tab = \is_string($tab) && \in_array($tab, self::TABS, true) ? $tab : null;
        // ?id=<adesione> senza ?tab: apre la scheda in cui si trova quell'acquisto (solo tra i PROPRI).
        if (null === $tab && \is_string($participationId)) {
            foreach ($rows as $row) {
                if ($row['id'] === $participationId) {
                    $tab = $row['tab'];
                    break;
                }
            }
        }
        $tab ??= $default;
        $sort = \is_string($sort) && \in_array($sort, self::SORTS, true) ? $sort : ('in-corso' === $tab ? 'scadenza' : 'recenti');

        $list = array_values(array_filter($rows, static fn (array $row): bool => $row['tab'] === $tab));
        usort($list, static function (array $a, array $b) use ($sort): int {
            $priority = static fn (array $row): int => \in_array($row['vista'], ['pagamento', 'pronto'], true) ? 0 : 1;
            if ($priority($a) !== $priority($b)) {
                return $priority($a) <=> $priority($b);
            }
            if ('scadenza' === $sort) {
                $left = $a['gruppo']['scade_tra_secondi'] ?? \PHP_INT_MAX;
                $right = $b['gruppo']['scade_tra_secondi'] ?? \PHP_INT_MAX;
                if ($left !== $right) {
                    return $left <=> $right;
                }
            }

            return [$b['data_prenotazione'], $b['id']] <=> [$a['data_prenotazione'], $a['id']];
        });

        return [
            'tab' => $tab,
            'sort' => $sort,
            'tab_predefinito' => $default,
            'conteggi' => $counts,
            'riepilogo' => ['gruppi_in_corso' => $open, 'ritiri_pronti' => $ready],
            'acquisti' => $list,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(User $user): array
    {
        $participations = $this->participations->findForUserWithCampaign($user);
        if ([] === $participations) {
            return [];
        }

        $campaigns = [];
        foreach ($participations as $participation) {
            $campaigns[$participation->getCampaign()->getId()->toRfc4122()] = $participation->getCampaign();
        }
        $campaigns = array_values($campaigns);

        $context = [
            'tiers' => $this->campaigns->findTiersByCampaign($campaigns),
            'images' => $this->campaigns->findImagesByProduct($campaigns),
            'deliveries' => $this->deliveries->findByParticipations($participations),
            'tokens' => $this->tokens->findByParticipations($participations),
            'paid' => $this->paidByParticipation($participations),
            'defaultPickupPoint' => $this->pickupPoints->findDefault(),
            'now' => \DateTimeImmutable::createFromInterface($this->clock->now()),
        ];

        return array_map(fn (Participation $participation): array => $this->row($participation, $context), $participations);
    }

    /**
     * Somma dei pagamenti confermati (acconto + saldo) per adesione, in centesimi: i rimborsati non contano.
     *
     * @param list<Participation> $participations
     *
     * @return array<string, int>
     */
    private function paidByParticipation(array $participations): array
    {
        /** @var list<Payment> $payments */
        $payments = $this->em->createQueryBuilder()
            ->select('pay')
            ->from(Payment::class, 'pay')
            ->where('IDENTITY(pay.participation) IN (:participations)')
            ->andWhere('pay.status = :status')
            ->setParameter('participations', array_map(static fn (Participation $p): string => $p->getId()->toBinary(), $participations), ArrayParameterType::BINARY)
            ->setParameter('status', PaymentStatus::Confermato)
            ->getQuery()
            ->getResult();

        $paid = [];
        foreach ($payments as $payment) {
            $key = $payment->getParticipation()->getId()->toRfc4122();
            $paid[$key] = ($paid[$key] ?? 0) + $payment->getAmountCents();
        }

        return $paid;
    }

    /**
     * @param array{tiers: array<string, list<PriceTier>>, images: array<string, list<\App\Campaign\Entity\ProductImage>>, deliveries: array<string, Delivery>, tokens: array<string, list<PickupToken>>, paid: array<string, int>, defaultPickupPoint: ?PickupPoint, now: \DateTimeImmutable} $context
     *
     * @return array<string, mixed>
     */
    private function row(Participation $participation, array $context): array
    {
        $campaign = $participation->getCampaign();
        $product = $campaign->getProduct();
        $supplier = $product->getSupplier();
        $id = $participation->getId()->toRfc4122();
        $tiers = $context['tiers'][$campaign->getId()->toRfc4122()] ?? [];
        $delivery = $context['deliveries'][$id] ?? null;
        $tokens = $context['tokens'][$id] ?? [];
        $now = $context['now'];

        $quantity = $participation->getQuantity();
        $current = $campaign->getCurrentQuantity();
        $minimum = $this->pricing->minimumQuantity($tiers, $campaign->getMoq());
        $reached = $current >= $minimum;
        $expired = $campaign->getDeadline() <= $now;
        $failedGroup = \in_array($campaign->getStatus(), [CampaignStatus::Fallita, CampaignStatus::Annullata], true);
        $home = DeliveryMode::ConsegnaDomicilio === $delivery?->getMode();
        $status = $participation->getStatus();
        $paid = $context['paid'][$id] ?? 0;

        $scanned = null;
        $active = false;
        foreach ($tokens as $token) {
            if (PickupTokenStatus::Scansionato === $token->getStatus()) {
                $scanned = $token;
            } elseif (PickupTokenStatus::Generato === $token->getStatus()) {
                $active = true;
            }
        }

        // Scheda e vista (legacy: acquisto_classifica).
        $tab = 'in-corso';
        $view = 'in_corso';
        $closing = false;
        $refundProcessing = false;
        if (ParticipationStatus::Annullata === $status) {
            [$tab, $view] = ['annullati', 'annullato'];
        } elseif (ParticipationStatus::Rimborsata === $status) {
            [$tab, $view] = ['annullati', 'rimborsato'];
        } elseif (\in_array($status, [ParticipationStatus::Prenotata, ParticipationStatus::Confermata, ParticipationStatus::AzioneRichiesta], true)) {
            if (ParticipationStatus::AzioneRichiesta !== $status && ($failedGroup || ($expired && !$reached))) {
                // Gruppo non riuscito: l'acconto viene rimborsato in automatico (a volte non ancora eseguito).
                [$tab, $view] = ['annullati', 'rimborsato'];
                $refundProcessing = true;
            } elseif (ParticipationStatus::AzioneRichiesta === $status) {
                [$tab, $view] = ['in-corso', 'pagamento'];
            } else {
                $view = $reached ? 'attivo' : 'in_corso';
                $closing = $expired;
            }
        } elseif (ParticipationStatus::Pagata === $status) {
            $collected = null !== $scanned || \in_array($delivery?->getStatus(), [DeliveryStatus::Ritirata, DeliveryStatus::Consegnata], true);
            if ($collected) {
                [$tab, $view] = ['conclusi', 'ritirato'];
            } elseif ($active && !$home && CampaignStatus::Consegnata === $campaign->getStatus()) {
                // Pronto solo quando la merce è arrivata (non appena addebitato il saldo).
                [$tab, $view] = ['in-arrivo', 'pronto'];
            } else {
                [$tab, $view] = ['in-arrivo', 'inviato'];
            }
        }

        $isOpen = \in_array($view, ['in_corso', 'attivo'], true);
        $label = 'ritirato' === $view && $home ? 'Consegnato' : self::LABELS[$view];
        $deposit = $participation->getDepositCents();

        $out = [
            'id' => $id,
            'numero_ordine' => strtoupper(substr(str_replace('-', '', $id), -8)),
            'id_colletta' => $campaign->getId()->toRfc4122(),
            'id_consegna' => $delivery?->getId()->toRfc4122(),
            'prodotto' => $product->getName(),
            'fornitore' => $supplier->getName(),
            'fornitore_id' => $supplier->getId()->toRfc4122(),
            'immagine' => ($context['images'][$product->getId()->toRfc4122()][0] ?? null)?->getUrl(),
            'quantita' => $quantity,
            'data_prenotazione' => $participation->getCreatedAt()->format(\DATE_ATOM),
            'stato_db' => $status->value,
            'tab' => $tab,
            'vista' => $view,
            'etichetta' => $label,
            'passo' => self::STEP[$view],
            'concluso' => 'ritirato' === $view,
            'spedizione' => $home,
            'consegna_modalita' => $delivery?->getMode()->value,
            'chiusura_in_corso' => $closing,
            'acconto_cents' => $deposit,
            'acconto_percent' => (float) $this->settings->depositPercent,
            'puo_annullare' => ParticipationStatus::Prenotata === $status && 'in_corso' === $view && !$expired && CampaignStatus::InCorso === $campaign->getStatus(),
            'annullo_bloccato' => 'attivo' === $view,
            // "Aggiorna il metodo di pagamento" prima della chiusura (dopo un saldo fallito c'è "Aggiorna pagamento").
            'puo_aggiornare_metodo' => \in_array($status, [ParticipationStatus::Prenotata, ParticipationStatus::Confermata], true) && $isOpen && null === $campaign->getClosedAt(),
            // "Compra di più" non è ancora migrato (legacy: lib/aumento.php).
            'puo_aumentare' => false,
            'puo_confermare_ricezione' => $home && DeliveryStatus::Spedita === $delivery->getStatus(),
            'qr_disponibile' => $active && 'pronto' === $view,
            'data_limite' => $campaign->getDeadline()->format(\DATE_ATOM),
            'saldo_scadenza' => 'pagamento' === $view ? $participation->getBalanceDueAt()?->format(\DATE_ATOM) : null,
        ];

        if ($isOpen) {
            $out['gruppo'] = [
                'quantita_attuale' => $current,
                'minimo' => $minimum,
                'obiettivo' => $this->goal($tiers, $campaign, $current),
                'scade_tra_secondi' => max(0, $campaign->getDeadline()->getTimestamp() - $now->getTimestamp()),
            ];
            // Stima di sola lettura, stessa formula della chiusura.
            $price = $this->pricing->estimatePrice($tiers, $current, $campaign->getCurrentPriceCents());
            $shipping = $home ? $delivery->getDeliveryCostCents() : 0;
            $breakdown = $this->pricing->balance($quantity, $price, $campaign->getCommissionRate(), $shipping, $deposit);
            $out['saldo_previsto_cents'] = max(0, $breakdown->balanceCents);
            $out['saldo_dettaglio'] = [
                'merce_cents' => $breakdown->goodsCents,
                'commissione_cents' => $breakdown->commissionCents,
                'spedizione_cents' => $breakdown->shippingCents,
                'totale_cents' => $breakdown->totalCents,
                'acconto_cents' => $breakdown->depositCents,
                'saldo_cents' => $breakdown->balanceCents,
                'stimato' => true,
                'spedizione_scelta' => $home,
            ];
            $out['data_addebito'] = $out['data_limite'];
        }

        if (\in_array($view, ['inviato', 'pronto', 'ritirato'], true)) {
            $out['totale_pagato_cents'] = $paid;
            // Risparmio rispetto al listino con il prezzo effettivamente applicato alla chiusura; assente se non calcolabile.
            $list = $campaign->getBasePriceCents();
            $applied = $this->pricing->balancePrice($campaign->getFinalPriceCents(), $tiers, $current, $campaign->getCurrentPriceCents());
            $saved = ($list - $applied) * $quantity;
            $out['risparmiato_cents'] = $list > 0 && $paid > 0 && $saved > 0 ? $saved : null;
        }

        if ('pronto' === $view || 'ritirato' === $view) {
            $point = $delivery?->getPickupPoint() ?? $campaign->getPickupPoint() ?? $context['defaultPickupPoint'];
            $out['ritiro'] = [
                'sede_nome' => $point?->getName(),
                'sede_indirizzo' => $point?->getAddress(),
                'sede_citta' => $point?->getCity(),
                'sede_orari' => $point?->getOpeningHours(),
                'data' => ($scanned?->getScannedAt() ?? $delivery?->getDeliveredAt())?->format(\DATE_ATOM),
            ];
        }

        if ('ritirato' === $view) {
            // Le recensioni (US-18) non sono ancora migrate: nessuna è mai "già fatta".
            $out['recensione'] = ['prodotto_fatta' => false, 'fornitore_fatta' => false];
        }

        if ('rimborsato' === $view) {
            $out['rimborso'] = [
                'importo_cents' => $deposit,
                'data' => $refundProcessing ? null : ($campaign->getClosedAt() ?? $campaign->getStatusUpdatedAt())?->format(\DATE_ATOM),
                'in_elaborazione' => $refundProcessing,
                'scadenza' => $out['data_limite'],
                'pezzi' => $current,
                'minimo' => $minimum,
            ];
        }

        return $out;
    }

    /**
     * Obiettivo della barra di avanzamento: un solo traguardo alla volta (legacy: obiettivo_barra).
     * 'attivazione' = prima del primo scaglione, 'prezzo' = verso il prossimo, 'massimo' = ultimo raggiunto.
     *
     * @param list<PriceTier> $tiers
     *
     * @return array{tipo: string, soglia: int, mancano: int, prezzo_cents: int, pct: float}
     */
    private function goal(array $tiers, Campaign $campaign, int $total): array
    {
        usort($tiers, static fn (PriceTier $a, PriceTier $b): int => $a->getThreshold() <=> $b->getThreshold());
        $steps = [] !== $tiers
            ? array_map(static fn (PriceTier $tier): array => [$tier->getThreshold(), $tier->getPriceCents()], $tiers)
            : [[max(1, $campaign->getMoq()), $campaign->getCurrentPriceCents()]];

        [$firstThreshold, $firstPrice] = $steps[0];
        if ($total < $firstThreshold) {
            return ['tipo' => 'attivazione', 'soglia' => $firstThreshold, 'mancano' => $firstThreshold - $total, 'prezzo_cents' => $firstPrice, 'pct' => $firstThreshold > 0 ? $total / $firstThreshold : 0.0];
        }
        foreach ($steps as [$threshold, $price]) {
            if ($total < $threshold) {
                return ['tipo' => 'prezzo', 'soglia' => $threshold, 'mancano' => $threshold - $total, 'prezzo_cents' => $price, 'pct' => $total / $threshold];
            }
        }
        [$lastThreshold, $lastPrice] = $steps[\count($steps) - 1];

        return ['tipo' => 'massimo', 'soglia' => $lastThreshold, 'mancano' => 0, 'prezzo_cents' => $lastPrice, 'pct' => 1.0];
    }
}
