<?php

namespace App\Participation\Controller;

use App\Identity\Entity\User;
use App\Participation\Service\DepositCheckoutService;
use App\Participation\Service\WithdrawalService;
use App\Shared\Http\ApiError;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "Unisciti al gruppo": l'adesione nasce solo da un acconto pagato (US-04).
 */
final class ParticipationController
{
    /** Adesione senza pagamento: disabilitata, come nel legacy. */
    #[Route('/api/campaigns/{id}/join', name: 'api_campaign_join', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function join(): never
    {
        throw new ApiError('FLUSSO_DISABILITATO', 'Usa il pulsante Unisciti al gruppo nella pagina del gruppo', 410);
    }

    /** Crea (o riusa) il pagamento dell'acconto su Stripe e restituisce l'indirizzo a cui mandare il cliente. */
    #[Route('/api/payments/deposit-checkout', name: 'api_payment_deposit_checkout', methods: ['POST'])]
    public function depositCheckout(Request $request, DepositCheckoutService $service, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($service->createCheckout($user, ApiResponse::body($request)));
    }

    /**
     * Il cliente ritira la propria adesione: solo prima della scadenza e finché il gruppo è sotto il minimo.
     * L'acconto viene rimborsato e le unità tornano disponibili.
     */
    #[Route('/api/campaigns/{id}/participation', name: 'api_campaign_withdraw', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    public function withdraw(string $id, WithdrawalService $service, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($service->withdraw($user, $id));
    }
}
