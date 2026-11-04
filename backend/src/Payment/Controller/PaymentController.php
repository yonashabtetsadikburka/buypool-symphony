<?php

namespace App\Payment\Controller;

use App\Identity\Entity\User;
use App\Participation\Service\BalanceService;
use App\Participation\Service\SucceededPaymentHandler;
use App\Payment\Gateway\StripeGateway;
use App\Payment\Gateway\StripeGatewayException;
use App\Payment\Repository\PaymentRepository;
use App\Shared\Http\ApiError;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Pagamento del saldo e controllo dell'esito dopo il ritorno da Stripe. Serve il login (vedi access_control).
 * L'acconto sta in {@see \App\Participation\Controller\ParticipationController}.
 */
final class PaymentController
{
    /** Il cliente paga il saldo (legacy: POST /pagamento/checkout). */
    #[Route('/api/payments/balance-checkout', name: 'api_payment_balance_checkout', methods: ['POST'])]
    public function balanceCheckout(Request $request, BalanceService $balances, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($balances->createCheckout($user, ApiResponse::body($request)));
    }

    /**
     * Esito di un pagamento dopo il redirect (legacy: GET /pagamento/stato). Se Stripe dice "pagato" ma il webhook
     * non è ancora arrivato (o in locale non c'è `stripe listen`), registra il pagamento subito con lo stesso
     * codice del webhook: è idempotente, quindi non si crea mai due volte.
     */
    #[Route('/api/payments/status', name: 'api_payment_status', methods: ['GET'])]
    public function status(
        Request $request,
        StripeGateway $stripe,
        PaymentRepository $payments,
        SucceededPaymentHandler $handler,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $sessionId = trim((string) $request->query->get('session_id', ''));
        if ('' === $sessionId) {
            throw new ApiError('SESSION_ID_MANCANTE', 'Session ID mancante');
        }

        try {
            $session = $stripe->retrieveCheckoutSession($sessionId);
            if (($session->metadata['utente_id'] ?? null) !== $user->getId()->toRfc4122()) {
                throw new ApiError('NON_AUTORIZZATO', 'Sessione di un altro utente', 403);
            }
            if ($session->isPaid() && '' !== $session->paymentIntentId && null === $payments->findOneByStripePaymentIntentId($session->paymentIntentId)) {
                $handler->handle($stripe->retrievePaymentIntent($session->paymentIntentId));
            }
        } catch (StripeGatewayException $e) {
            throw new ApiError('STRIPE_ERRORE', 'Errore nel recupero stato: '.$e->getMessage());
        }

        return ApiResponse::ok([
            'status' => $session->paymentStatus,
            'prenotazione' => $session->metadata['prenotazione_id'] ?? null,
            'importo_cents' => $session->amountTotalCents,
        ]);
    }
}
