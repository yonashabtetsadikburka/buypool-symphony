<?php

namespace App\Campaign\Application;

use App\Campaign\Enum\CampaignStatus;

/**
 * Cosa può fare l'admin su un gruppo, in base alla sua situazione (legacy: stato_lavoro.php,
 * AZIONI_PER_STATO). Funzione pura: non legge né scrive nulla.
 *
 * Situazioni (le stesse dello "stato di lavoro" del legacy, senza le bozze che qui non esistono):
 *  - aperto: non scaduto, minimo non raggiunto. Senza adesioni si cambia tutto e si può eliminare.
 *  - attivo: non scaduto, minimo raggiunto.
 *  - scaduto_da_chiudere: scaduto con minimo raggiunto e chiusura non ancora fatta.
 *  - bloccato: tutto il resto (non attivato, chiuso, ordine al fornitore, consegnato, annullato…).
 */
final class CampaignActions
{
    public const APERTO = 'aperto';
    public const ATTIVO = 'attivo';
    public const SCADUTO_DA_CHIUDERE = 'scaduto_da_chiudere';
    public const BLOCCATO = 'bloccato';

    private function __construct(
        public readonly string $situation,
        /** Minimo, scaglioni, prezzi e commissione. */
        public readonly bool $canEditEverything,
        /** Nome e descrizione del prodotto. */
        public readonly bool $canEditTexts,
        /** Spostare la scadenza in avanti. */
        public readonly bool $canExtendDeadline,
        public readonly bool $canDelete,
        /** Annullare il gruppo rimborsando tutti (legacy: azione "annulla"). */
        public readonly bool $canCancel = false,
    ) {
    }

    public static function resolve(
        CampaignStatus $status,
        \DateTimeImmutable $deadline,
        bool $minimumReached,
        bool $closed,
        int $liveParticipations,
        \DateTimeImmutable $now,
    ): self {
        if (!\in_array($status, [CampaignStatus::InCorso, CampaignStatus::Riuscita], true)) {
            return new self(self::BLOCCATO, false, false, false, false);
        }

        if ($deadline > $now) {
            if (!$minimumReached) {
                $free = 0 === $liveParticipations;

                return new self(self::APERTO, $free, true, true, $free, true);
            }

            return new self(self::ATTIVO, false, true, true, false, true);
        }

        if ($minimumReached && !$closed) {
            return new self(self::SCADUTO_DA_CHIUDERE, false, true, false, false, true);
        }

        return new self(self::BLOCCATO, false, false, false, false);
    }

    /** Etichetta italiana per i messaggi d'errore. */
    public function label(): string
    {
        return match ($this->situation) {
            self::APERTO => 'Aperto',
            self::ATTIVO => 'Attivo',
            self::SCADUTO_DA_CHIUDERE => 'Scaduto da chiudere',
            default => 'non modificabile',
        };
    }
}
