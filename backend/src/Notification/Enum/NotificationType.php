<?php

namespace App\Notification\Enum;

enum NotificationType: string
{
    case Scadenza = 'SCADENZA';
    case OrdineDisponibile = 'ORDINE_DISPONIBILE';
    case OrdineInviato = 'ORDINE_INVIATO';
    case Ritirato = 'RITIRATO';
    case PropostaApprovata = 'PROPOSTA_APPROVATA';
    case PagamentoRiuscito = 'PAGAMENTO_RIUSCITO';
    case PagamentoRicevuto = 'PAGAMENTO_RICEVUTO';
    case PagamentoFallito = 'PAGAMENTO_FALLITO';
    case Rimborso = 'RIMBORSO';
    case MoqRaggiunto = 'MOQ_RAGGIUNTO';
    case OrdineConfermato = 'ORDINE_CONFERMATO';
    case MercePronta = 'MERCE_PRONTA';
    case Spedito = 'SPEDITO';
    case NuovoScaglione = 'NUOVO_SCAGLIONE';
    case Tracciamento = 'TRACCIAMENTO';
    case Sistema = 'SISTEMA';
    case OrdineRicevuto = 'ORDINE_RICEVUTO';

    /**
     * Missing from the legacy ENUM (the legacy insert failed for this type). Present here so the bug is gone.
     */
    case SogliaPersa = 'SOGLIA_PERSA';
}
