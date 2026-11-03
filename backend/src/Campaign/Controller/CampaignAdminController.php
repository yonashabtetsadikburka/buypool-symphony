<?php

namespace App\Campaign\Controller;

use App\Campaign\Application\CampaignAdminService;
use App\Identity\Entity\User;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Gestione dei gruppi da parte dell'admin (US-10). Il ruolo è imposto da access_control in
 * config/packages/security.yaml: senza login 401, senza ruolo admin 403.
 */
final class CampaignAdminController
{
    #[Route('/api/campaigns', name: 'api_campaign_create', methods: ['POST'])]
    public function create(Request $request, CampaignAdminService $service, #[CurrentUser] User $admin): JsonResponse
    {
        return ApiResponse::ok($service->create($admin, ApiResponse::body($request)), 201);
    }

    #[Route('/api/campaigns/{id}', name: 'api_campaign_update', requirements: ['id' => Requirement::UUID], methods: ['PATCH'])]
    public function update(string $id, Request $request, CampaignAdminService $service): JsonResponse
    {
        return ApiResponse::ok(['id' => $id, 'modificati' => $service->update($id, ApiResponse::body($request))]);
    }

    #[Route('/api/campaigns/{id}', name: 'api_campaign_delete', requirements: ['id' => Requirement::UUID], methods: ['DELETE'])]
    public function delete(string $id, CampaignAdminService $service): JsonResponse
    {
        $service->delete($id);

        return ApiResponse::ok(['eliminata' => true]);
    }
}
