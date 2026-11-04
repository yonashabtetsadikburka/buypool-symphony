<?php

namespace App\Delivery\Controller;

use App\Delivery\Service\DeliveryChoiceService;
use App\Delivery\Service\DeliveryReceiptService;
use App\Delivery\Service\PickupService;
use App\Identity\Entity\User;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Consegna (US-06), QR di ritiro (US-08) e conferma di ricezione (US-09).
 * Il login è imposto da access_control; lo scan del QR è solo admin.
 */
final class DeliveryController
{
    /** Costo fisso della spedizione a domicilio, da mostrare prima della scelta. */
    #[Route('/api/deliveries/cost', name: 'api_delivery_cost', methods: ['GET'])]
    public function cost(DeliveryChoiceService $service): JsonResponse
    {
        return ApiResponse::ok(['costo_spedizione_cents' => $service->homeDeliveryCostCents()]);
    }

    /** Sceglie ritiro in sede o spedizione a domicilio per un'adesione ancora da pagare. */
    #[Route('/api/deliveries/choice', name: 'api_delivery_choice', methods: ['POST'])]
    public function choice(Request $request, DeliveryChoiceService $service, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($service->choose($user, ApiResponse::body($request)));
    }

    /** Il cliente conferma di aver ricevuto la spedizione. */
    #[Route('/api/deliveries/{id}/confirm-receipt', name: 'api_delivery_confirm_receipt', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function confirmReceipt(string $id, DeliveryReceiptService $service, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($service->confirm($user, $id));
    }

    /** Il cliente vede il suo QR (un codice nuovo a ogni visualizzazione). */
    #[Route('/api/participations/{id}/pickup-qr', name: 'api_participation_pickup_qr', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function pickupQr(string $id, PickupService $service, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($service->show($user, $id));
    }

    /** L'admin scansiona il QR e segna il ritiro. Una volta sola per codice. */
    #[Route('/api/pickups/{token}/scan', name: 'api_pickup_scan', requirements: ['token' => '[^/]+'], methods: ['POST'])]
    public function scan(string $token, PickupService $service): JsonResponse
    {
        return ApiResponse::ok($service->scan($token));
    }
}
