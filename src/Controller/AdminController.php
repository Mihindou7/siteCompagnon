<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/admin')]
final class AdminController extends AbstractController
{
    private function denyIfNotAdmin(): void
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
    }

    #[Route('', methods: ['GET'])]
    public function dashboard(UserRepository $repo): JsonResponse
    {
        $this->denyIfNotAdmin();

        $users = $repo->findAll();
        $total = count($users);
        $byRole = ['ROLE_USER' => 0, 'ROLE_SELLER' => 0, 'ROLE_ADMIN' => 0];

        foreach ($users as $user) {
            foreach ($user->getRoles() as $role) {
                if (isset($byRole[$role])) {
                    $byRole[$role]++;
                }
            }
        }

        return $this->json([
            'total_users' => $total,
            'by_role'     => $byRole,
        ]);
    }

    #[Route('/users', methods: ['GET'])]
    public function listUsers(UserRepository $repo): JsonResponse
    {
        $this->denyIfNotAdmin();

        $users = array_map(fn(User $u) => [
            'id'    => $u->getId(),
            'email' => $u->getEmail(),
            'roles' => $u->getRoles(),
        ], $repo->findAll());

        return $this->json($users);
    }

    #[Route('/users/{id}', methods: ['GET'])]
    public function showUser(int $id, UserRepository $repo): JsonResponse
    {
        $this->denyIfNotAdmin();

        $user = $repo->find($id);
        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable'], 404);
        }

        return $this->json([
            'id'    => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);
    }

    #[Route('/users/{id}/role', methods: ['PATCH'])]
    public function updateRole(int $id, Request $request, UserRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $this->denyIfNotAdmin();

        $user = $repo->find($id);
        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable'], 404);
        }

        $data = json_decode($request->getContent(), true);
        $allowedRoles = ['ROLE_USER', 'ROLE_SELLER', 'ROLE_ADMIN'];

        if (!isset($data['role']) || !in_array($data['role'], $allowedRoles, true)) {
            return $this->json(['message' => 'Rôle invalide. Choisissez : ROLE_USER, ROLE_SELLER ou ROLE_ADMIN'], 400);
        }

        $user->setRoles([$data['role']]);
        $em->flush();

        return $this->json([
            'message' => 'Rôle mis à jour',
            'id'      => $user->getId(),
            'email'   => $user->getEmail(),
            'roles'   => $user->getRoles(),
        ]);
    }

    #[Route('/users/{id}', methods: ['DELETE'])]
    public function deleteUser(int $id, UserRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        $this->denyIfNotAdmin();

        $user = $repo->find($id);
        if (!$user) {
            return $this->json(['message' => 'Utilisateur introuvable'], 404);
        }

        if ($user === $this->getUser()) {
            return $this->json(['message' => 'Vous ne pouvez pas supprimer votre propre compte'], 403);
        }

        $em->remove($user);
        $em->flush();

        return $this->json(['message' => 'Utilisateur supprimé']);
    }
}
