<?php

namespace App\Identity\Security;

use App\Identity\Entity\User;
use App\Identity\Enum\AccountStatus;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Un account sospeso non accede. Il controllo è dopo la verifica della password:
 * chi non conosce la password non scopre se l'account è sospeso.
 */
final class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if ($user instanceof User && AccountStatus::Sospeso === $user->getStatus()) {
            throw new CustomUserMessageAccountStatusException("Account sospeso: non puoi accedere. Contatta l'assistenza.");
        }
    }
}
