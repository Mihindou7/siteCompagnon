<?php

namespace App\Controller\User;

use App\Controller\AbstractApiController;
use App\DTO\User\ChangePasswordDTO;
use App\DTO\User\UpdateProfileDTO;
use App\Entity\User;
use App\Service\AuthService;
use App\Service\UploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/me')]
#[IsGranted('ROLE_USER')]
class UserController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function me(#[CurrentUser] User $user): JsonResponse
    {
        return $this->success($this->serializeUser($user));
    }

    #[Route('', methods: ['PATCH'])]
    public function update(
        #[CurrentUser] User $user,
        #[MapRequestPayload] UpdateProfileDTO $dto,
        EntityManagerInterface $em,
    ): JsonResponse {
        if ($dto->firstName !== null) {
            $user->setFirstName($dto->firstName);
        }
        if ($dto->lastName !== null) {
            $user->setLastName($dto->lastName);
        }
        if ($dto->phone !== null) {
            $user->setPhone($dto->phone);
        }

        $user->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->success($this->serializeUser($user));
    }

    #[Route('/password', methods: ['PATCH'])]
    public function changePassword(
        #[CurrentUser] User $user,
        #[MapRequestPayload] ChangePasswordDTO $dto,
        UserPasswordHasherInterface $hasher,
        AuthService $authService,
        EntityManagerInterface $em,
    ): JsonResponse {
        if ($dto->newPassword !== $dto->newPasswordConfirm) {
            return $this->error('Passwords do not match.', 422);
        }

        // If user has a password, require current password
        if ($user->getPasswordHash() !== null) {
            if ($dto->currentPassword === null || !$hasher->isPasswordValid($user, $dto->currentPassword)) {
                return $this->error('Current password is incorrect.', 400);
            }
        }

        $user->setPasswordHash($hasher->hashPassword($user, $dto->newPassword));
        $user->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        // Revoke all other sessions
        $authService->revokeAllRefreshTokens($user);

        return $this->success(['message' => 'Mot de passe modifié']);
    }

    #[Route('/avatar', methods: ['PATCH'])]
    public function uploadAvatar(
        #[CurrentUser] User $user,
        Request $request,
        UploadService $uploadService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $file = $request->files->get('avatar');
        if (!$file) {
            return $this->error('No file uploaded.', 400);
        }

        // Delete old avatar
        if ($user->getAvatarUrl()) {
            $uploadService->delete($user->getAvatarUrl());
        }

        $url = $uploadService->uploadAvatar($file);
        $user->setAvatarUrl($url);
        $user->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->success(['avatar_url' => $url]);
    }

    #[Route('', methods: ['DELETE'])]
    public function deleteAccount(
        #[CurrentUser] User $user,
        Request $request,
        UserPasswordHasherInterface $hasher,
        AuthService $authService,
        EntityManagerInterface $em,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true) ?? [];

        // Password required unless Google-only account
        if ($user->getPasswordHash() !== null) {
            $password = $data['password'] ?? null;
            if (!$password || !$hasher->isPasswordValid($user, $password)) {
                return $this->error('Password confirmation is required.', 400);
            }
        }

        $this->anonymizeUser($user, $em);
        $authService->revokeAllRefreshTokens($user);

        // Archive seller animals
        if ($user->getSeller()) {
            foreach ($user->getSeller()->getAnimals() as $animal) {
                if ($animal->getStatus() !== 'sold') {
                    $animal->setStatus('archived');
                }
            }
        }

        $em->flush();

        return $this->noContent();
    }

    private function anonymizeUser(User $user, EntityManagerInterface $em): void
    {
        $hash = substr(md5($user->getEmail()), 0, 8);
        $user->setEmail("deleted_{$hash}@deleted.local");
        $user->setFirstName('Compte');
        $user->setLastName('Supprimé');
        $user->setPhone(null);
        $user->setAvatarUrl(null);
        $user->setPasswordHash(null);
        $user->setStatus('disabled');
        $user->setUpdatedAt(new \DateTimeImmutable());
    }

    public function serializeUser(User $user): array
    {
        $data = [
            'id'          => $user->getId(),
            'email'       => $user->getEmail(),
            'first_name'  => $user->getFirstName(),
            'last_name'   => $user->getLastName(),
            'phone'       => $user->getPhone(),
            'avatar_url'  => $user->getAvatarUrl(),
            'roles'       => $user->getRoles(),
            'is_verified' => $user->isEmailVerified(),
            'status'      => $user->getStatus(),
            'created_at'  => $user->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];

        if ($user->getSeller() !== null) {
            $data['seller'] = [
                'id'              => $user->getSeller()->getId(),
                'name'            => $user->getSeller()->getName(),
                'verified_status' => $user->getSeller()->getVerifiedStatus(),
            ];
        } else {
            $data['seller'] = null;
        }

        return $data;
    }
}
