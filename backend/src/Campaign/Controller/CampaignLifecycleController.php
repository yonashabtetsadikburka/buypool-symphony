<?php

namespace App\Campaign\Controller;

use App\Campaign\Application\CampaignAdminService;
use App\Campaign\Application\CampaignCancellationService;
use App\Campaign\Application\CampaignClosureService;
use App\Campaign\Application\GoodsArrivalService;
use App\Campaign\Entity\Campaign;
use App\Identity\Entity\User;
use App\Shared\Http\ApiError;
use App\Shared\Http\ApiResponse;
use App\Supplier\Service\SupplierOrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Uid\Uuid;

/**
 * Ciclo di vita del gruppo per l'admin (US-11): chiusura con addebito dei saldi, annullamento con rimborsi,
 * invio dell'ordine al fornitore. Il ruolo è imposto da access_control (senza login 401, non admin 403).
 */
final class CampaignLifecycleController
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Chiude subito un gruppo già scaduto (di norma lo fa il comando pianificato). Una seconda richiesta dà 409.
     */
    #[Route('/api/campaigns/{id}/close', name: 'api_campaign_close', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function close(string $id, CampaignClosureService $closure): JsonResponse
    {
        $campaign = $this->find($id);
        $now = new \DateTimeImmutable();
        if ($campaign->getDeadline() > $now) {
            throw new ApiError('CHIUSURA_ANTICIPATA', 'Chiusura possibile solo dopo la scadenza', 409);
        }
        if (null !== $campaign->getClosedAt()) {
            throw new ApiError('GIA_CHIUSA', 'Il gruppo è già stato chiuso', 409);
        }

        $result = $closure->close($campaign, $now);
        if ($result->alreadyClosed) {
            throw new ApiError('GIA_CHIUSA', 'Il gruppo è già stato chiuso', 409);
        }

        return ApiResponse::ok($result->toArray());
    }

    /**
     * Annulla il gruppo e rimborsa tutti. Motivo obbligatorio (lo vedono i clienti) e conferma scrivendo ANNULLA.
     */
    #[Route('/api/campaigns/{id}/cancel', name: 'api_campaign_cancel', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function cancel(
        string $id,
        Request $request,
        CampaignCancellationService $cancellation,
        CampaignAdminService $admin,
        #[CurrentUser] User $user,
    ): JsonResponse {
        $body = ApiResponse::body($request);
        $reason = \is_string($body['motivo'] ?? null) ? trim($body['motivo']) : '';
        if (mb_strlen($reason) < 5) {
            throw new ApiError('MOTIVO_OBBLIGATORIO', "Scrivi il motivo dell'annullamento: lo vedranno i clienti (almeno 5 caratteri)", 422);
        }
        if (mb_strlen($reason) > 255) {
            throw new ApiError('MOTIVO_TROPPO_LUNGO', "Il motivo può avere al massimo 255 caratteri", 422);
        }
        if ('ANNULLA' !== trim(\is_string($body['conferma'] ?? null) ? $body['conferma'] : '')) {
            throw new ApiError('CONFERMA_MANCANTE', 'Per confermare scrivi ANNULLA', 422);
        }

        $campaign = $this->find($id);
        // Un gruppo già annullato con rimborsi a metà si riprende dal giro pianificato, non da qui.
        if (!$admin->actionsFor($campaign, new \DateTimeImmutable())->canCancel) {
            throw new ApiError('AZIONE_NON_CONSENTITA', 'Azione non consentita: il gruppo non si può annullare nello stato attuale', 409);
        }

        $result = $cancellation->cancel($campaign, $reason, $user);

        return ApiResponse::ok(['rimborsate' => $result->refunded, 'falliti' => $result->failed, 'completo' => $result->complete]);
    }

    /**
     * Segna l'ordine come inviato al fornitore. Possibile una volta sola: la seconda richiesta dà 409.
     */
    #[Route('/api/campaigns/{id}/send-to-supplier', name: 'api_campaign_send_to_supplier', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function sendToSupplier(string $id, SupplierOrderService $orders, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($orders->send($this->find($id), $user));
    }

    /**
     * "Merce arrivata": il gruppo diventa "consegnata", nascono i QR di ritiro e i clienti vengono avvisati.
     */
    #[Route('/api/campaigns/{id}/goods-arrived', name: 'api_campaign_goods_arrived', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function goodsArrived(string $id, GoodsArrivalService $arrival, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($arrival->markArrived($this->find($id), $user));
    }

    private function find(string $id): Campaign
    {
        $campaign = $this->em->find(Campaign::class, Uuid::fromString($id));
        if (!$campaign instanceof Campaign) {
            throw new ApiError('CAMPAGNA_INESISTENTE', 'Gruppo non trovato', 404);
        }

        return $campaign;
    }
}
