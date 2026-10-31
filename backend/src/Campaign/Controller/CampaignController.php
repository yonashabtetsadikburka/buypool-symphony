<?php

namespace App\Campaign\Controller;

use App\Campaign\Http\CampaignPresenter;
use App\Campaign\Repository\CampaignRepository;
use App\Shared\Http\ApiError;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Uid\Uuid;

/**
 * Vetrina pubblica dei gruppi: visibile anche agli ospiti, senza dati personali.
 */
final class CampaignController
{
    #[Route('/api/campaigns', name: 'api_campaign_list', methods: ['GET'])]
    public function list(CampaignRepository $campaigns, CampaignPresenter $presenter): JsonResponse
    {
        return ApiResponse::ok($presenter->list($campaigns->findForCatalog()));
    }

    #[Route('/api/campaigns/{id}', name: 'api_campaign_detail', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function detail(string $id, CampaignRepository $campaigns, CampaignPresenter $presenter): JsonResponse
    {
        $campaign = $campaigns->findOneForCatalog(Uuid::fromString($id));
        if (null === $campaign) {
            throw new ApiError('CAMPAGNA_INESISTENTE', 'Gruppo non trovato', 404);
        }

        return ApiResponse::ok($presenter->detail($campaign));
    }
}
