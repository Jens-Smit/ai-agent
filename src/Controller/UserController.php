<?php
// src/Controller/UserController.php

namespace App\Controller;

use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class UserController extends AbstractController
{
    #[Route('/api/user', name: 'api_get_current_user', methods: ['GET'])]
    #[OA\Get(
        path: "/api/user",
        summary: "Get current authenticated user",
        description: "Returns the currently authenticated user's data",
        tags: ["User"],
        responses: [
            new OA\Response(
                response: 200,
                description: "User data",
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: "user", type: "object",
                            properties: [
                                new OA\Property(property: "id", type: "integer"),
                                new OA\Property(property: "email", type: "string"),
                                new OA\Property(property: "name", type: "string", nullable: true),
                                new OA\Property(property: "roles", type: "array", items: new OA\Items(type: "string"))
                            ]
                        )
                    ]
                )
            ),
            new OA\Response(response: 401, description: "Not authenticated")
        ]
    )]
    public function getCurrentUser(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        return $this->json([
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getUserIdentifier(),
                'name' => method_exists($user, 'getName') ? $user->getName() : null,
                'roles' => $user->getRoles(),
            ]
        ]);
    }

    #[Route('/api/user', name: 'api_update_user', methods: ['PUT'])]
    #[OA\Put(
        path: "/api/user",
        summary: "Update current user profile",
        description: "Updates the current user's profile information",
        tags: ["User"],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: "name", type: "string")
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: "Profile updated"),
            new OA\Response(response: 401, description: "Not authenticated")
        ]
    )]
    public function updateProfile(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        // Implement profile update logic here
        
        return $this->json([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getUserIdentifier(),
            ]
        ]);
    }
}