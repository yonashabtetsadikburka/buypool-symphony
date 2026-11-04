<?php

namespace App\Campaign\Application;

use App\Campaign\Entity\Campaign;
use App\Campaign\Enum\CampaignStatus;
use App\Campaign\Enum\CampaignTransition;
use App\Identity\Entity\User;
use App\Participation\Enum\ParticipationStatus;
use App\Participation\Repository\ParticipationRepository;
use App\Payment\Enum\PaymentType;
use App\Payment\Service\RefundService;
use App\Shared\Http\ApiError;
use App\Shared\Persistence\NamedLock;
use App\Shared\Persistence\TransactionRunner;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annulla un gruppo e rimborsa TUTTI, acconti e saldi già addebitati (legacy: gruppo_annulla_e_rimborsa).
 * Lo usano l'admin e il saldo non pagato che porta il gruppo sotto il minimo. Rientrante: richiamarlo su un gruppo
 * già annullato riprende solo le adesioni non ancora rimborsate, senza duplicare nulla.
 */
final class CampaignCancellationService
{
    /** Motivo mostrato ai clienti quando un saldo non pagato porta il gruppo sotto il minimo. */
    public const REASON_UNPAID_BALANCE = 'un partecipante non ha completato il pagamento e il gruppo non raggiunge più il minimo';

    private const LIVE = [
        ParticipationStatus::Prenotata,
        ParticipationStatus::Confermata,
        ParticipationStatus::Pagata,
        ParticipationStatus::AzioneRichiesta,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TransactionRunner $transaction,
        private readonly NamedLock $locks,
        private readonly CampaignTransitioner $transitioner,
        private readonly ParticipationRepository $participations,
        private readonly RefundService $refunds,
    ) {
    }

    /**
     * @throws ApiError 409 se il gruppo ha già un ordine al fornitore o se un altro processo lo sta già chiudendo
     */
    public function cancel(Campaign $campaign, ?string $reason, ?User $admin, ?\DateTimeImmutable $now = null): CancellationResult
    {
        $result = $this->locks->runExclusive(
            CampaignClosureService::lockName($campaign),
            fn (): CancellationResult => $this->doCancel($campaign, $reason, $admin, $now ?? new \DateTimeImmutable()),
        );

        return $result ?? throw new ApiError('OPERAZIONE_IN_CORSO', "Il gruppo è in elaborazione (chiusura in corso): riprova tra poco", 409);
    }

    private function doCancel(Campaign $campaign, ?string $reason, ?User $admin, \DateTimeImmutable $now): CancellationResult
    {
        $this->transaction->run(function () use ($campaign, $reason, $admin): void {
            $this->em->refresh($campaign);
            if (\in_array($campaign->getStatus(), [CampaignStatus::OrdineFornitore, CampaignStatus::Consegnata], true)) {
                throw new ApiError('GRUPPO_NON_ANNULLABILE', "Il gruppo ha già un ordine al fornitore: non si può annullare", 409);
            }
            if (CampaignStatus::Annullata !== $campaign->getStatus()) {
                $this->transitioner->apply($campaign, CampaignTransition::Cancel, $admin);
                $given = trim((string) $reason);
                $campaign->setCancellationReason('' !== $given ? $given : self::REASON_UNPAID_BALANCE);
            } elseif (null === $campaign->getCancellationReason()) {
                $campaign->setCancellationReason(self::REASON_UNPAID_BALANCE);
            }
        });

        $refunded = 0;
        $failed = 0;
        foreach ($this->participations->findByCampaignAndStatuses($campaign, self::LIVE) as $participation) {
            $done = $this->transaction->run(function () use ($participation): bool {
                $result = $this->refunds->refund($participation, [PaymentType::Acconto, PaymentType::Saldo]);
                if ($result->isComplete()) {
                    $participation->setStatus(ParticipationStatus::Rimborsata)->setBalanceDueAt(null);
                }

                return $result->isComplete();
            });
            $done ? ++$refunded : ++$failed;
        }

        $complete = 0 === $failed;
        if ($complete && null === $campaign->getClosedAt()) {
            $this->transaction->run(static fn () => $campaign->setClosedAt($now));
        }

        return new CancellationResult($refunded, $failed, $complete);
    }
}
