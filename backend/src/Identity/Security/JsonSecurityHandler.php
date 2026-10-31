<?php

namespace App\Identity\Security;

use App\Identity\Entity\User;
use App\Shared\Http\ApiResponse;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Exception\AccountStatusException;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * Risposte JSON della sicurezza: login riuscito/fallito, 401 senza sessione, 403 senza permessi, logout.
 */
final class JsonSecurityHandler implements AuthenticationSuccessHandlerInterface, AuthenticationFailureHandlerInterface, AuthenticationEntryPointInterface, AccessDeniedHandlerInterface, EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [LogoutEvent::class => 'onLogout'];
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $user = $token->getUser();

        return ApiResponse::ok($user instanceof User ? self::describe($user) : null);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Stesso errore per email sconosciuta e password errata: non rivela quale campo è sbagliato.
        if ($exception instanceof AccountStatusException) {
            return ApiResponse::error('UTENTE_SOSPESO', $exception->getMessageKey(), 403);
        }

        return ApiResponse::error('CREDENZIALI_NON_VALIDE', 'Email o password non corrette.', 401);
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiResponse::error('NON_AUTENTICATO', 'Devi effettuare il login', 401);
    }

    public function handle(Request $request, AccessDeniedException $accessDeniedException): Response
    {
        return ApiResponse::error('NON_AUTORIZZATO', 'Non hai i permessi per questa operazione', 403);
    }

    public function onLogout(LogoutEvent $event): void
    {
        $event->setResponse(ApiResponse::ok(['uscito' => true]));
    }

    /**
     * @return array{id: string, nome: string, cognome: string, email: string, ruolo: string, stato: string}
     */
    public static function describe(User $user): array
    {
        return [
            'id' => $user->getId()->toRfc4122(),
            'nome' => $user->getFirstName(),
            'cognome' => $user->getLastName() ?? '',
            'email' => $user->getEmail(),
            'ruolo' => $user->getRole()->value,
            'stato' => $user->getStatus()->value,
        ];
    }
}
