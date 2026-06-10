<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class MeController extends AbstractController
{
    #[Route('/api/me', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $user = $this->getUser();

        if (!$user) {
            return new JsonResponse([
                'message' => 'Not authenticated'
            ], 401);
        }

        return new JsonResponse([
            'email' => $user->getUserIdentifier(),
            'roles' => $user->getRoles()
        ]);
    }
}