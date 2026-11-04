<?php

namespace App\Campaign\Command;

use App\Campaign\Application\CampaignClosureService;
use App\Participation\Service\BalanceExpiryService;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Giro pianificato (cron o Utilità di pianificazione di Windows, ogni ora): chiude i gruppi scaduti, addebita i saldi,
 * rimborsa e annulla le adesioni il cui saldo non è stato pagato entro il termine.
 * Equivale agli script cron del legacy (chiusura, saldi_scaduti). Sicuro da rilanciare: ogni passo è idempotente.
 *
 *   php bin/console app:campaigns:run-scheduled
 */
#[AsCommand(name: 'app:campaigns:run-scheduled', description: 'Chiude i gruppi scaduti e annulla i saldi non pagati entro il termine')]
final class CloseExpiredCampaignsCommand extends Command
{
    public function __construct(
        private readonly CampaignClosureService $closure,
        private readonly BalanceExpiryService $expiry,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = \DateTimeImmutable::createFromInterface($this->clock->now());

        $results = $this->closure->closeExpired($now);
        $failed = 0;
        foreach ($results as $result) {
            $io->writeln(\sprintf(
                'Gruppo %s: %d pagate, %d da sistemare, %d rimborsate, %d rimborsi falliti, %d da riprendere%s',
                $result->campaignId,
                $result->paid,
                $result->actionRequired,
                $result->refunded,
                $result->refundsFailed,
                $result->skipped,
                $result->closed ? ' → chiuso' : '',
            ));
            $failed += $result->refundsFailed;
        }
        if ([] === $results) {
            $io->writeln('Nessun gruppo da chiudere.');
        }

        $expiry = $this->expiry->expire($now);
        $io->writeln(\sprintf(
            'Saldi scaduti: %d adesioni annullate, %d gruppi annullati, %d rimborsi falliti.',
            $expiry->cancelledParticipations,
            $expiry->cancelledGroups,
            $expiry->refundsFailed,
        ));
        $failed += $expiry->refundsFailed;

        // Un rimborso fallito non è un errore del comando (si riprova al prossimo giro) ma va visto: codice di uscita 1.
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
