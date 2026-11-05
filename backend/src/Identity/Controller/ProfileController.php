<?php

namespace App\Identity\Controller;

use App\Identity\Entity\User;
use App\Identity\Service\ProfileService;
use App\Shared\Http\ApiResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Profilo del cliente collegato (US-22): lettura, aggiornamento dei dati e cambio password.
 * Il login è imposto da access_control.
 */
final class ProfileController
{
    #[Route('/api/profile', name: 'api_profile_show', methods: ['GET'])]
    public function show(ProfileService $profile, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($profile->describe($user));
    }

    #[Route('/api/profile', name: 'api_profile_update', methods: ['PUT', 'PATCH'])]
    public function update(Request $request, ProfileService $profile, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($profile->update($user, ApiResponse::body($request)));
    }

    #[Route('/api/profile/password', name: 'api_profile_password', methods: ['POST'])]
    public function password(Request $request, ProfileService $profile, #[CurrentUser] User $user): JsonResponse
    {
        return ApiResponse::ok($profile->changePassword($user, ApiResponse::body($request)));
    }
}
