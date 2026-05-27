<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class AuthController extends AbstractController
{
   #[Route('/api/register', methods: ['POST'])]
public function register(
    Request $request,
    EntityManagerInterface $em,
    UserPasswordHasherInterface $hasher
): JsonResponse {

    $data = json_decode($request->getContent(), true);

    if (!isset($data['email']) || !isset($data['password'])) {
        return $this->json([
            'message' => 'Email et password requis'
        ], 400);
    }

    $user = new User();
    $user->setEmail($data['email']);

    $hashedPassword = $hasher->hashPassword($user, $data['password']);
    $user->setPassword($hashedPassword);

    $user->setRoles(['ROLE_USER']); 

    $em->persist($user);
    $em->flush();

    return new JsonResponse([
        'message' => 'User created successfully'
    ], 201);
}
   #[Route('/api/login', name: 'api_login', methods: ['POST'])]
    #[OA\RequestBody(
        description: 'Identifiants de connexion',
        required: true,
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'email', type: 'string', example: 'admin@bookapi.com'),
                new OA\Property(property: 'password', type: 'string', example: 'password')
            ]
        )
    )]
    #[OA\Response(
        response: 200,
        description: 'Token JWT généré avec succès',
        content: new OA\JsonContent(
            properties: [
                new OA\Property(property: 'token', type: 'string', example: 'eyJ0eXAiOiJKV1QiLCJhbGciOiJSUzI1NiJ9...')
            ]
        )
    )]
    #[OA\Response(
        response: 401,
        description: 'Identifiants invalides'
    )]
    #[OA\Tag(name: 'Auth')]
    public function login(): JsonResponse
    {
        // Si le code arrive ici, c'est que le firewall n'a pas intercepté la requête (mauvaise config)
        // Ou que vous testez la méthode directement sans passer par le firewall
        return new JsonResponse(['message' => 'Cette route est gérée par le Firewall JWT'], 401);
    }
}