<?php

namespace App\Supplier\Controller;

use App\Identity\Entity\User;
use App\Identity\Security\JsonSecurityHandler;
use App\Shared\Http\ApiResponse;
use App\Supplier\Service\SupplierFulfillmentService;
use App\Supplier\Service\SupplierInviteService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Attivazione dell'account fornitore da invito e avanzamento degli ordini (US-12).
 * I ruoli sono imposti da access_control: invito = admin, verifica/attivazione = pubblici, /api/supplier = fornitore.
 */
final class SupplierController
{
    /** L'admin genera il link di invito per un fornitore senza account (valido 7 giorni). */
    #[Route('/api/admin/suppliers/{id}/invite', name: 'api_admin_supplier_invite', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function invite(string $id, SupplierInviteService $invites): JsonResponse
    {
        return ApiResponse::ok($invites->generate($id), 201);
    }

    /** Dati non sensibili per la pagina di attivazione; invito non valido: 410. */
    #[Route('/api/suppliers/invite/{token}', name: 'api_supplier_invite_check', requirements: ['token' => '[^/]+'], methods: ['GET'])]
    public function checkInvite(string $token, SupplierInviteService $invites): JsonResponse
    {
        return ApiResponse::ok($invites->verify($token));
    }

    /** Il fornitore sceglie la password e attiva l'account; resta collegato (accesso immediato). */
    #[Route('/api/suppliers/activate', name: 'api_supplier_activate', methods: ['POST'])]
    public function activate(Request $request, SupplierInviteService $invites, Security $security): JsonResponse
    {
        $user = $invites->activate(ApiResponse::body($request));
        $security->login($user, 'security.authenticator.json_login.main', 'main');

        return ApiResponse::ok(JsonSecurityHandler::describe($user), 201);
    }

    #[Route('/api/supplier/orders', name: 'api_supplier_orders', methods: ['GET'])]
    public function orders(SupplierFulfillmentService $fulfillment, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($fulfillment->list($user));
    }

    #[Route('/api/supplier/orders/{id}/confirm', name: 'api_supplier_order_confirm', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function confirm(string $id, SupplierFulfillmentService $fulfillment, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($fulfillment->confirm($user, $id));
    }

    #[Route('/api/supplier/orders/{id}/ship', name: 'api_supplier_order_ship', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function ship(string $id, Request $request, SupplierFulfillmentService $fulfillment, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($fulfillment->ship($user, $id, ApiResponse::body($request)));
    }
}
