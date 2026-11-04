<?php

namespace App\Participation\Service;

use App\Payment\Gateway\PaymentIntentData;

/**
 * Un PaymentIntent riuscito su Stripe: in base al tipo nei metadati è un acconto (nasce l'adesione) o un saldo
 * (l'adesione diventa pagata). Lo usano il webhook e, per il caso di webhook in ritardo, il controllo dopo il pagamento.
 * I tipi non portati (es. "aumento", US-27) sono ignorati.
 */
final class SucceededPaymentHandler
{
    public function __construct(
        private readonly DepositConfirmationService $deposits,
        private readonly BalanceService $balances,
    ) {
    }

    public function handle(PaymentIntentData $intent): PaymentOutcome
    {
        return match ($intent->metadata['tipo'] ?? '') {
            'acconto' => $this->deposits->confirm(new PaidDeposit($intent->id, $intent->amountCents, $intent->paymentMethod, $intent->status, $intent->metadata)),
            'saldo' => $this->balances->complete($intent),
            default => PaymentOutcome::Ignored,
        };
    }
}
