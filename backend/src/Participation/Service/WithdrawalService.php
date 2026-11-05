<?php

namespace App\Participation\Service;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Identity\Entity\User;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use App\Notification\Service\NotificationService;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Payment\Service\RefundService;
use App\Pricing\PricingCalculator;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Ritiro della propria adesione (legacy: partecipazioni_ritira).
 *
 * Si può uscire solo con l'adesione "prenotata", prima della scadenza e finché il gruppo è sotto il minimo (primo
 * scaglione): dopo il minimo l'adesione non si annulla più. L'acconto viene rimborsato per intero; se il rimborso
 * non riesce non cambia nulla. L'adesione resta nel database come "annullata" (storico dei pagamenti intatto) e le
 * unità tornano disponibili. Il gruppo è bloccato per tutta l'operazione, così un acconto che porta il gruppo al minimo
 * nello stesso momento non si incrocia con un ritiro.
 */
final class WithdrawalService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly ParticipationRepository $participations,
        private readonly RefundService $refunds,
        private readonly PricingCalculator $pricing,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @return array{stato: string, rimborsato_cents: int}
     */
    public function withdraw(User $user, string $campaignId): array
    {
        $campaignUuid = Uuid::isValid($campaignId) ? Uuid::fromString($campaignId) : null;
        if (null === $campaignUuid || null === $this->em->find(Campaign::class, $campaignUuid)) {
            throw new ApiError('CAMPAGNA_INESISTENTE', 'Gruppo non trovato', 404);
        }

        return $this->transaction->run(function () use ($user, $campaignUuid): array {
            $campaign = $this->em->find(Campaign::class, $campaignUuid, LockMode::PESSIMISTIC_WRITE) ?? throw new ApiError('CAMPAGNA_INESISTENTE', 'Gruppo non trovato', 404);
            $this->em->refresh($campaign);

            $participation = $this->participations->findOneByCampaignAndUser($campaign, $user);
            if (null === $participation || ParticipationStatus::Annullata === $participation->getStatus()) {
                throw new ApiError('NON_PARTECIPI', 'Non risulti iscritto a questo gruppo', 404);
            }
            $this->em->refresh($participation);

            if (CampaignStatus::OrdineFornitore === $campaign->getStatus()) {
                throw new ApiError('CAMPAGNA_ORDINATA', "Non si puo' uscire da un gruppo già ordinato", 409);
            }
            if (CampaignStatus::Consegnata === $campaign->getStatus()) {
                throw new ApiError('CAMPAGNA_CONSEGNATA', "Non si puo' uscire da un gruppo consegnato", 409);
            }
            if ($campaign->getDeadline() <= new \DateTimeImmutable()) {
                throw new ApiError('CAMPAGNA_SCADUTA', 'Il gruppo è scaduto: non puoi più annullare la tua adesione.', 409);
            }
            if (\in_array($participation->getStatus(), [ParticipationStatus::Pagata, ParticipationStatus::Rimborsata], true)) {
                throw new ApiError('PAGAMENTO_EFFETTUATO', "Non puoi annullare: pagamento già effettuato. Contatta l'assistenza per un rimborso.", 403);
            }
            // Dopo il minimo l'adesione non si annulla più: solo "prenotata" e solo sotto il minimo.
            if (ParticipationStatus::Prenotata !== $participation->getStatus()
                || CampaignStatus::InCorso !== $campaign->getStatus()
                || !$this->pricing->isBelowMinimum($campaign->getPriceTiers(), $campaign->getMoq(), $campaign->getCurrentQuantity())) {
                throw new ApiError('ANNULLO_NON_CONSENTITO', 'Il gruppo ha raggiunto il minimo: la tua adesione non si può più annullare.', 409);
            }

            $refunded = 0;
            if ($participation->getDepositCents() > 0) {
                $result = $this->refunds->refund($participation);
                if ($result->failed > 0) {
                    // Tutto o niente: la transazione torna indietro, l'adesione resta com'era e il cliente può riprovare.
                    throw new ApiError('RIMBORSO_FALLITO', "Rimborso acconto non completato: riprova tra poco o contatta l'assistenza.", 500);
                }
                $refunded = $result->refundedCents;
            }

            $participation->setStatus(ParticipationStatus::Annullata)->setBalanceDueAt(null);
            $campaign->setCurrentQuantity(max(0, $campaign->getCurrentQuantity() - $participation->getQuantity()));

            if ($refunded > 0) {
                $this->notifications->notify(
                    $user,
                    NotificationType::Rimborso,
                    'Adesione annullata',
                    \sprintf('Hai annullato l\'adesione a "%s". Ti abbiamo rimborsato l\'acconto di €%s.',$campaign->getProduct()->getName(), number_format($refunded / 100, 2, ',', '.')),
                    ReferenceType::Colletta,
                    $campaign->getId()->toRfc4122(),
                );
            }

            return ['stato' => $campaign->getStatus()->value, 'rimborsato_cents' => $refunded];
        });
    }
}
