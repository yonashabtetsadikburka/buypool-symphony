<?php

namespace App\Participation\Service;

use App\Campaign\Entity\Campaign;
use App\Campaign\Entity\PriceTier;
use App\Campaign\Enum\CampaignStatus;
use App\Delivery\Entity\PickupPoint;
use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Repository\PickupPointRepository;
use App\Identity\Entity\User;
use App\Participation\Repository\ParticipationRepository;
use App\Payment\Gateway\CheckoutSession;
use App\Payment\Gateway\CheckoutRequest;
use App\Payment\Gateway\StripeGateway;
use App\Payment\Gateway\StripeGatewayException;
use App\Payment\Service\StripeCustomerProvider;
use App\Pricing\Exception\DepositBelowMinimumException;
use App\Pricing\PricingCalculator;
use App\Shared\Http\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Uid\Uuid;

/**
 * Prepara il pagamento dell'acconto per "Unisciti al gruppo" (legacy: pagamento_prepara_acconto e
 * pagamento_acconto_checkout). L'adesione NON nasce qui: nasce quando Stripe conferma il pagamento
 * ({@see DepositConfirmationService}).
 */
final class DepositCheckoutService
{
    private const MAX_QUANTITY = 99;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ParticipationRepository $participations,
        private readonly PickupPointRepository $pickupPoints,
        private readonly PricingCalculator $pricing,
        private readonly StripeGateway $stripe,
        private readonly StripeCustomerProvider $customers,
        #[Autowire(env: 'FRONTEND_URL')]
        private readonly string $frontendUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $data { colletta_id, quantita?, delivery: { tipo, id_sede? | via, cap, citta, provincia? } }
     *
     * @return array{checkout_url: string, session_id: string, importo_cents: int}
     */
    public function createCheckout(User $user, array $data): array
    {
        $now = new \DateTimeImmutable();
        $campaign = $this->findCampaign($data['colletta_id'] ?? null);
        $quantity = $this->quantity($data['quantita'] ?? 1);
        [$mode, $pickupPoint, $address] = $this->delivery($data['delivery'] ?? null, $campaign);

        if (!\in_array($campaign->getStatus(), [CampaignStatus::InCorso, CampaignStatus::Riuscita], true)) {
            throw new ApiError('CAMPAGNA_NON_APERTA', 'Il gruppo non è aperto alle adesioni', 409);
        }
        // Scaduto con il minimo raggiunto resta "riuscita" fino alla chiusura: l'adesione va rifiutata comunque.
        if ($campaign->getDeadline() <= $now) {
            throw new ApiError('CAMPAGNA_SCADUTA', 'Il gruppo è scaduto: non si può più aderire', 409);
        }
        if (null !== $this->participations->findOneBy(['campaign' => $campaign, 'user' => $user])) {
            throw new ApiError('GIA_PARTECIPI', 'Hai già partecipato a questo gruppo', 409);
        }

        try {
            $deposit = $this->pricing->deposit($quantity, $this->firstTierPrice($campaign));
        } catch (DepositBelowMinimumException) {
            throw new ApiError('ACCONTO_MINIMO', 'Acconto minimo €0,50', 422);
        }

        $campaignId = $campaign->getId()->toRfc4122();
        $userId = $user->getId()->toRfc4122();
        $metadata = [
            'tipo' => 'acconto',
            'colletta_id' => $campaignId,
            'quantita' => (string) $quantity,
            'utente_id' => $userId,
            'delivery_type' => $mode->value,
            'id_sede' => $pickupPoint?->getId()->toRfc4122() ?? '',
            'indirizzo' => mb_substr($address ?? '', 0, 400),
        ];

        try {
            $customerId = $this->customers->customerIdFor($user);
            $session = $this->createOrReuse($customerId, $userId, $campaignId, $metadata, $deposit, new CheckoutRequest(
                customerId: $customerId,
                productName: \sprintf('Acconto %s — %d unità', $campaign->getProduct()->getName(), $quantity),
                description: 'Acconto gruppo '.$campaignId,
                amountCents: $deposit,
                successUrl: $this->groupUrl($campaignId).'?pagamento=in_verifica&session_id={CHECKOUT_SESSION_ID}',
                cancelUrl: $this->groupUrl($campaignId),
                metadata: $metadata,
                idempotencyKey: \sprintf('acconto_%s_%s_%d_%d_%d', $userId, $campaignId, $quantity, $deposit, intdiv(time(), 1800)),
            ));
        } catch (StripeGatewayException $e) {
            throw new ApiError('STRIPE_ERRORE', 'Errore Stripe: '.$e->getMessage(), 500);
        }

        return ['checkout_url' => $session->url, 'session_id' => $session->id, 'importo_cents' => $deposit];
    }

    /**
     * Un solo pagamento attivo per cliente e gruppo: un secondo clic riusa la sessione aperta
     * (o la sostituisce se la scelta è cambiata) invece di crearne un'altra che potrebbe essere pagata due volte.
     *
     * @param array<string, string> $metadata
     */
    private function createOrReuse(string $customerId, string $userId, string $campaignId, array $metadata, int $amountCents, CheckoutRequest $request): CheckoutSession
    {
        $connection = $this->em->getConnection();
        $lockName = 'bp_co_'.md5($userId.'_'.$campaignId);
        if (1 !== (int) $connection->fetchOne('SELECT GET_LOCK(?, 0)', [$lockName])) {
            throw new ApiError('CHECKOUT_IN_CORSO', "Stai già completando questo pagamento: attendi un istante", 409);
        }

        try {
            $reusable = null;
            foreach ($this->stripe->openCheckoutSessions($customerId) as $open) {
                $sameScope = ($open->metadata['tipo'] ?? null) === 'acconto'
                    && ($open->metadata['colletta_id'] ?? null) === $campaignId
                    && ($open->metadata['utente_id'] ?? null) === $userId;
                if (!$sameScope) {
                    continue;
                }
                if (null === $reusable && $open->amountCents === $amountCents && '' !== $open->url && $open->metadata == $metadata) {
                    $reusable = $open;
                    continue;
                }
                $this->stripe->expireCheckoutSession($open->id);
            }

            return $reusable ?? $this->stripe->createDepositCheckout($request);
        } finally {
            $connection->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]);
        }
    }

    private function findCampaign(mixed $id): Campaign
    {
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new ApiError('DATI_NON_VALIDI', 'Indica il gruppo (colletta_id)', 400, ['colletta_id' => 'Gruppo non valido']);
        }
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($id));
        if (!$campaign instanceof Campaign) {
            throw new ApiError('CAMPAGNA_INESISTENTE', 'Gruppo non trovato', 404);
        }

        return $campaign;
    }

    /** Come nel legacy la quantità resta tra 1 e 99; un valore non intero è un errore. */
    private function quantity(mixed $value): int
    {
        if (!\is_int($value)) {
            throw new ApiError('DATI_NON_VALIDI', 'La quantità deve essere un numero intero', 400, ['quantita' => 'Quantità non valida']);
        }

        return max(1, min(self::MAX_QUANTITY, $value));
    }

    /**
     * @return array{DeliveryMode, ?PickupPoint, ?string}
     */
    private function delivery(mixed $raw, Campaign $campaign): array
    {
        if (!\is_array($raw)) {
            throw new ApiError('SCELTA_MANCANTE', "Scegli come ricevere l'articolo");
        }
        $mode = \is_string($raw['tipo'] ?? null) ? DeliveryMode::tryFrom($raw['tipo']) : null;
        if (null === $mode) {
            throw new ApiError('SCELTA_NON_VALIDA', 'Metodo di consegna non valido');
        }

        if (DeliveryMode::RitiroSede === $mode) {
            $pickupPoint = $campaign->getPickupPoint() ?? $this->pickupPoints->findDefault();
            if (null === $pickupPoint) {
                throw new ApiError('RITIRO_NON_DISPONIBILE', 'Ritiro in sede non disponibile', 409);
            }
            if (($raw['id_sede'] ?? null) !== $pickupPoint->getId()->toRfc4122()) {
                throw new ApiError('SEDE_NON_VALIDA', 'Punto di ritiro non valido');
            }

            return [$mode, $pickupPoint, null];
        }

        $street = $this->text($raw['via'] ?? null);
        $zip = $this->text($raw['cap'] ?? null);
        $city = $this->text($raw['citta'] ?? null);
        $province = $this->text($raw['provincia'] ?? null);
        if ('' === $street || '' === $zip || '' === $city) {
            throw new ApiError('INDIRIZZO_MANCANTE', 'Via, CAP e città sono obbligatori per la spedizione');
        }

        return [$mode, null, \sprintf('%s, %s %s%s', $street, $zip, $city, '' !== $province ? ' ('.strtoupper($province).')' : '')];
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }

    /** Base dell'acconto: prezzo del primo scaglione, oppure il prezzo corrente se non ci sono scaglioni. */
    private function firstTierPrice(Campaign $campaign): int
    {
        $tiers = $campaign->getPriceTiers()->toArray();
        if ([] === $tiers) {
            return $campaign->getCurrentPriceCents();
        }
        usort($tiers, static fn (PriceTier $a, PriceTier $b): int => $a->getThreshold() <=> $b->getThreshold());

        return $tiers[0]->getPriceCents();
    }

    private function groupUrl(string $campaignId): string
    {
        return rtrim($this->frontendUrl, '/').'/campaigns/'.$campaignId;
    }
}
