<?php

namespace App\Payment\Service;

use App\Participation\Entity\Participation;
use App\Payment\Enum\PaymentStatus;
use App\Payment\Enum\PaymentType;
use App\Payment\Gateway\StripeGateway;
use App\Payment\Gateway\StripeGatewayException;
use App\Payment\Repository\PaymentRepository;
use Psr\Log\LoggerInterface;

/**
 * Rimborso totale dei pagamenti confermati di un'adesione (legacy: rimborsa_acconti). Idempotente:
 * i pagamenti già rimborsati non si toccano e Stripe riceve una chiave di idempotenza per pagamento
 * ("rimborso_<PaymentIntent>"), quindi non esistono due rimborsi dello stesso pagamento.
 * Il chiamante salva (flush): qui si cambiano solo le righe dei pagamenti.
 */
final class RefundService
{
    public function __construct(
        private readonly StripeGateway $stripe,
        private readonly PaymentRepository $payments,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<PaymentType> $types di norma solo l'acconto; con il gruppo annullato anche il saldo
     */
    public function refund(Participation $participation, array $types = [PaymentType::Acconto]): RefundResult
    {
        $refunded = 0;
        $failed = 0;
        foreach ($this->payments->findConfirmed($participation, $types) as $payment) {
            $intent = $payment->getStripePaymentIntentId();
            if (null === $intent || '' === $intent) {
                continue;   // niente addebitato su Stripe (es. saldo a zero): non c'è nulla da rimborsare
            }
            try {
                $this->stripe->refundPaymentIntent($intent, 'rimborso_'.$intent);
            } catch (StripeGatewayException $e) {
                // Solo il codice breve: niente testo libero né dati personali nel database e nei log.
                $payment->setStripeStatus(mb_substr('rimborso_ko:'.($e->stripeCode ?? 'errore'), 0, 50));
                $this->logger->error('Rimborso non riuscito', ['pagamento' => $payment->getId()->toRfc4122(), 'codice' => $e->stripeCode ?? 'errore']);
                ++$failed;
                continue;
            }
            $payment->setStatus(PaymentStatus::Rimborsato);
            $refunded += $payment->getAmountCents();
        }

        return new RefundResult($refunded, $failed);
    }
}
