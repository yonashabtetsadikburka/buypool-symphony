<?php

declare(strict_types=1);

namespace App\Shared\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class HealthController extends AbstractController
{
    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        try {
            $connection->fetchOne('SELECT 1');
        } catch (\Throwable) {
            return new JsonResponse(['status' => 'error', 'checks' => ['database' => 'error']], 503);
        }

        return new JsonResponse(['status' => 'ok', 'checks' => ['database' => 'ok']]);
    }
}
