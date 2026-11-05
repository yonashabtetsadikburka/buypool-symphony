<?php

namespace App\Notification\Controller;

use App\Identity\Entity\User;
use App\Notification\Service\NotificationInboxService;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Le mie notifiche (US-23, parte lettura). Il login è imposto da access_control.
 */
final class NotificationController
{
    #[Route('/api/notifications', name: 'api_notification_list', methods: ['GET'])]
    public function list(NotificationInboxService $inbox, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($inbox->list($user));
    }

    #[Route('/api/notifications/unread-count', name: 'api_notification_unread_count', methods: ['GET'])]
    public function unreadCount(NotificationInboxService $inbox, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($inbox->unreadCount($user));
    }

    #[Route('/api/notifications/read-all', name: 'api_notification_read_all', methods: ['POST'])]
    public function readAll(NotificationInboxService $inbox, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($inbox->markAllRead($user));
    }

    #[Route('/api/notifications/{id}/read', name: 'api_notification_read', requirements: ['id' => Requirement::UUID], methods: ['PUT', 'POST'])]
    public function read(string $id, NotificationInboxService $inbox, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($inbox->markRead($user, $id));
    }
}
