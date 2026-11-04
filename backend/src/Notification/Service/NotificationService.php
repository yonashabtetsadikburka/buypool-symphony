<?php

namespace App\Notification\Service;

use App\Identity\Entity\User;
use App\Notification\Entity\Notification;
use App\Notification\Enum\NotificationType;
use App\Notification\Enum\ReferenceType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Notifica in-app per un cliente. Salva soltanto: il flush lo fa la transazione di chi la chiama,
 * così la notifica nasce o sparisce insieme all'operazione che l'ha causata.
 * (Email, elenco e "segna come letta" arrivano con la US-23.)
 */
final class NotificationService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function notify(User $user, NotificationType $type, string $title, string $message, ?ReferenceType $referenceType = null, ?string $referenceId = null): Notification
    {
        $notification = (new Notification($user, $type, $title, $message))->setReference($referenceType, $referenceId);
        $this->em->persist($notification);

        return $notification;
    }
}
