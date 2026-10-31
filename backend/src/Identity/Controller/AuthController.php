<?php

namespace App\Identity\Controller;

use App\Identity\Entity\User;
use App\Identity\Security\JsonSecurityHandler;
use App\Identity\Service\RegistrationService;
use App\Shared\Http\ApiResponse;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Registrazione e identità. Il login (POST /api/auth/login) e il logout (POST /api/auth/logout)
 * sono gestiti dal firewall: vedi config/packages/security.yaml.
 */
final class AuthController
{
    #[Route('/api/auth/register', name: 'api_auth_register', methods: ['POST'])]
    public function register(Request $request, RegistrationService $registration, Security $security): JsonResponse
    {
        $user = $registration->register(ApiResponse::body($request));

        // Accesso immediato dopo la registrazione, come nel legacy.
        $security->login($user, 'security.authenticator.json_login.main', 'main');

        return ApiResponse::ok(JsonSecurityHandler::describe($user), 201);
    }

    #[Route('/api/auth/me', name: 'api_auth_me', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok(JsonSecurityHandler::describe($user));
    }
}
