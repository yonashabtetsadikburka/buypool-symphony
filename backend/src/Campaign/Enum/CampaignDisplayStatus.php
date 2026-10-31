<?php

namespace App\Campaign\Enum;

/**
 * Stato del gruppo mostrato al pubblico (legacy: stato_campagna_display). Non è salvato:
 * deriva dallo stato interno, dalla scadenza, dal minimo e dalla chiusura.
 */
enum CampaignDisplayStatus: string
{
    case InCorso = 'in_corso';
    case InChiusura = 'in_chiusura';
    case Conclusa = 'conclusa';
    case NonRiuscita = 'non_riuscita';

    public static function resolve(
        CampaignStatus $status,
        \DateTimeImmutable $deadline,
        int $currentQuantity,
        int $minimumQuantity,
        ?\DateTimeImmutable $closedAt,
        \DateTimeImmutable $now,
    ): self {
        if (\in_array($status, [CampaignStatus::OrdinePronto, CampaignStatus::OrdineFornitore, CampaignStatus::Consegnata], true)) {
            return self::Conclusa;
        }
        if (\in_array($status, [CampaignStatus::Fallita, CampaignStatus::Annullata], true)) {
            return self::NonRiuscita;
        }
        if ($deadline >= $now) {
            return self::InCorso;
        }
        if ($currentQuantity < $minimumQuantity) {
            return self::NonRiuscita;
        }

        return null !== $closedAt ? self::Conclusa : self::InChiusura;
    }
}
