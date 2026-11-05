<?php

namespace App\Notification\Service;

use App\Identity\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Repository\NotificationRepository;
use App\Shared\Http\ApiError;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Notifiche in-app del cliente (US-23, parte lettura; legacy: endpoints/notifiche.php): elenco, conteggio delle non lette,
 * "segna come letta" e "segna tutte come lette". Ognuno vede e tocca solo le proprie (una notifica altrui è "inesistente").
 */
final class NotificationInboxService
{
    public const LIMIT = 200;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Le più recenti per prime, al massimo 200 come nel legacy.
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $user): array
    {
        return array_map(static fn (Notification $n): array => [
            'id' => $n->getId()->toRfc4122(),
            'tipo' => $n->getType()->value,
            'titolo' => $n->getTitle(),
            'messaggio' => $n->getMessage(),
            'tipo_riferimento' => $n->getReferenceType()?->value,
            'id_riferimento' => $n->getReferenceId(),
            'letta' => $n->isRead(),
            'data_creazione' => $n->getCreatedAt()->format(\DATE_ATOM),
        ], $this->notifications->findLatestForUser($user, self::LIMIT));
    }

    /**
     * @return array{non_lette: int}
     */
    public function unreadCount(User $user): array
    {
        return ['non_lette' => $this->notifications->countUnreadForUser($user)];
    }

    /**
     * @return array{letta: bool}
     */
    public function markRead(User $user, string $id): array
    {
        $notification = Uuid::isValid($id) ? $this->em->find(Notification::class, Uuid::fromString($id)) : null;
        if (!$notification instanceof Notification || !$notification->getUser()->getId()->equals($user->getId())) {
            throw new ApiError('NOTIFICA_INESISTENTE', 'Notifica non trovata', 404);
        }
        if (!$notification->isRead()) {
            $notification->markRead();
            $this->em->flush();
        }

        return ['letta' => true];
    }

    /**
     * @return array{aggiornate: int}
     */
    public function markAllRead(User $user): array
    {
        return ['aggiornate' => $this->notifications->markAllReadForUser($user)];
    }
}
