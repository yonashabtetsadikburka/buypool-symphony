<?php

namespace App\Participation\Controller;

use App\Identity\Entity\User;
use App\Participation\Service\PurchasesService;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * "I miei acquisti" (US-21): le adesioni del cliente collegato, in sola lettura.
 */
final class PurchasesController
{
    #[Route('/api/me/purchases', name: 'api_me_purchases', methods: ['GET'])]
    public function list(Request $request, PurchasesService $service, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($service->list(
            $user,
            $request->query->get('tab'),
            $request->query->get('sort'),
            $request->query->get('id'),
        ));
    }
}
