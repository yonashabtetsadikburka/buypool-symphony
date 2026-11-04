<?php

namespace App\Delivery\Service;

use App\Delivery\Entity\Delivery;
use App\Delivery\Enum\DeliveryMode;
use App\Delivery\Repository\DeliveryRepository;
use App\Delivery\Repository\PickupPointRepository;
use App\Identity\Entity\User;
use App\Participation\Entity\Participation;
use App\Participation\Enum\ParticipationStatus;
use App\Pricing\PricingCalculator;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Scelta (o cambio) della consegna di un'adesione prima del saldo (legacy: consegne_scelta, consegne_costo).
 * Ritiro in sede: gratis. Spedizione a domicilio: costo fisso da configurazione e indirizzo completo.
 * Il saldo (BalanceService) legge la consegna al momento di calcolarlo, quindi la scelta vale fino al pagamento.
 */
final class DeliveryChoiceService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly PricingCalculator $pricing,
        private readonly DeliveryRepository $deliveries,
        private readonly PickupPointRepository $pickupPoints,
    ) {
    }

    /** Costo della spedizione a domicilio, in centesimi (si mostra prima della scelta). */
    public function homeDeliveryCostCents(): int
    {
        return $this->pricing->shipping(DeliveryMode::ConsegnaDomicilio);
    }

    /**
     * @param array<string, mixed> $data { prenotazione_id, modalita, indirizzo?: { via, cap, citta, provincia? }, id_sede? }
     *
     * @return array{modalita: string, importo_consegna_cents: int, indirizzo: ?string, sede: ?string}
     */
    public function choose(User $user, array $data): array
    {
        $mode = \is_string($data['modalita'] ?? null) ? DeliveryMode::tryFrom($data['modalita']) : null;
        if (null === $mode) {
            throw new ApiError('MODALITA_NON_VALIDA', 'Modalita di consegna non valida');
        }
        $id = $data['prenotazione_id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new ApiError('PRENOTAZIONE_NON_TROVATA', 'Adesione non trovata', 404);
        }

        return $this->transaction->run(function () use ($user, $data, $mode, $id): array {
            $participation = $this->em->find(Participation::class, Uuid::fromString($id), LockMode::PESSIMISTIC_WRITE);
            if (!$participation instanceof Participation || !$participation->getUser()->getId()->equals($user->getId())) {
                throw new ApiError('PRENOTAZIONE_NON_TROVATA', 'Adesione non trovata', 404);
            }
            $this->em->refresh($participation);
            if (ParticipationStatus::Confermata !== $participation->getStatus()) {
                throw new ApiError('SCELTA_NON_CONSENTITA', "La scelta e' possibile solo prima del pagamento", 409);
            }

            $pickupPoint = null;
            $address = null;
            if (DeliveryMode::RitiroSede === $mode) {
                $pickupPoint = $participation->getCampaign()->getPickupPoint() ?? $this->pickupPoints->findDefault();
                if (null === $pickupPoint) {
                    throw new ApiError('RITIRO_NON_DISPONIBILE', 'Ritiro in sede non disponibile', 409);
                }
                $requested = $data['id_sede'] ?? null;
                if (null !== $requested && $requested !== $pickupPoint->getId()->toRfc4122()) {
                    throw new ApiError('SEDE_NON_VALIDA', 'Punto di ritiro non valido');
                }
            } else {
                $address = $this->address($user, $data['indirizzo'] ?? null);
            }

            $delivery = $this->deliveries->findOneBy(['participation' => $participation]);
            if (null === $delivery) {
                $delivery = new Delivery($participation, $mode);
                $this->em->persist($delivery);
            }
            $delivery->setMode($mode)
                ->setPickupPoint($pickupPoint)
                ->setDeliveryAddress($address)
                ->setDeliveryCostCents($this->pricing->shipping($mode));

            return [
                'modalita' => $mode->value,
                'importo_consegna_cents' => $delivery->getDeliveryCostCents(),
                'indirizzo' => $address,
                'sede' => $pickupPoint?->getName(),
            ];
        });
    }

    /**
     * Indirizzo di spedizione: quello indicato nella richiesta, altrimenti quello del profilo (come nel legacy).
     * Via, CAP e città sono obbligatori: se mancano, 422 con l'errore sul campo.
     */
    private function address(User $user, mixed $raw): string
    {
        $raw = \is_array($raw) ? $raw : [];
        $street = $this->text($raw['via'] ?? $user->getAddress());
        $zip = $this->text($raw['cap'] ?? $user->getZipCode());
        $city = $this->text($raw['citta'] ?? $user->getCity());
        $province = $this->text($raw['provincia'] ?? $user->getProvince());

        $missing = [];
        foreach (['via' => $street, 'cap' => $zip, 'citta' => $city] as $field => $value) {
            if ('' === $value) {
                $missing[$field] = 'Campo obbligatorio';
            }
        }
        if ([] !== $missing) {
            throw new ApiError('INDIRIZZO_MANCANTE', 'Completa indirizzo, CAP e città (anche dal tuo Profilo)', 422, $missing);
        }

        return \sprintf('%s, %s %s%s', $street, $zip, $city, '' !== $province ? ' ('.strtoupper($province).')' : '');
    }

    private function text(mixed $value): string
    {
        return \is_string($value) ? trim($value) : '';
    }
}
