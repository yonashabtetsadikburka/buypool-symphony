<?php

namespace App\Proposal\Enum;

enum ProposalStatus: string
{
    case InAttesa = 'in_attesa';
    case InVotazione = 'in_votazione';
    case ApprovataAdmin = 'approvata_admin';
    case Rifiutata = 'rifiutata';
    case Pubblicata = 'pubblicata';
    case RespintaVotazione = 'respinta_votazione';
}
