<?php

namespace App\Controller\Admin;

use App\Controller\AbstractApiController;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuditService;
use App\Service\AuthService;
use App\Service\PaginationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/users')]
#[IsGranted('ROLE_ADMIN')]
class UserAdminController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $page   = (int) $request->query->get('page', 1);
        $limit  = (int) $request->query->get('limit', 20);
        $status = $request->query->get('status');
        $search = $request->query->get('search');

        $qb = $repo->createQueryBuilder('u')
            ->leftJoin('u.seller', 's')
            ->addSelect('s')
            ->orderBy('u.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('u.status = :status')->setParameter('status', $status);
        }
        if ($search) {
            $qb->andWhere('u.email LIKE :search OR u.firstName LIKE :search OR u.lastName LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        $result = $paginator->paginate($qb, $page, $limit);
        $result['data'] = array_map(fn(User $u) => $this->serializeUserSummary($u), $result['data']);

        return $this->json($result);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id, UserRepository $repo): JsonResponse
    {
        $user = $repo->find($id);
        if (!$user) return $this->error('User not found.', 404);

        return $this->success($this->serializeUserFull($user));
    }

    #[Route('/{id}/toggle-status', methods: ['PATCH'])]
    public function toggleStatus(
        int $id,
        #[CurrentUser] User $admin,
        UserRepository $repo,
        EntityManagerInterface $em,
        AuditService $audit,
        AuthService $authService,
    ): JsonResponse {
        $user = $repo->find($id);
        if (!$user) return $this->error('User not found.', 404);
        if ($user->getId() === $admin->getId()) {
            return $this->error('Cannot disable your own account.', 403);
        }

        $oldStatus = $user->getStatus();
        $newStatus = $oldStatus === 'active' ? 'disabled' : 'active';

        $user->setStatus($newStatus);
        $user->setUpdatedAt(new \DateTimeImmutable());

        $archivedCount = 0;
        if ($newStatus === 'disabled') {
            if ($user->getSeller()) {
                foreach ($user->getSeller()->getAnimals() as $animal) {
                    if ($animal->getStatus() !== 'sold') {
                        $animal->setStatus('archived');
                        $animal->setUpdatedAt(new \DateTimeImmutable());
                        $archivedCount++;
                    }
                }
            }
            $authService->revokeAllRefreshTokens($user);
        }

        $em->flush();

        $audit->log(
            $newStatus === 'disabled' ? 'user.disabled' : 'user.enabled',
            'User',
            $user->getId(),
            $admin,
            ['status' => $oldStatus],
            ['status' => $newStatus, 'archived_animals_count' => $archivedCount]
        );

        return $this->success([
            'id'                     => $user->getId(),
            'status'                 => $newStatus,
            'archived_animals_count' => $archivedCount,
        ]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(
        int $id,
        #[CurrentUser] User $admin,
        UserRepository $repo,
        EntityManagerInterface $em,
        AuditService $audit,
        AuthService $authService,
    ): JsonResponse {
        $user = $repo->find($id);
        if (!$user) return $this->error('User not found.', 404);

        $hash = substr(md5($user->getEmail() . $user->getId()), 0, 8);
        $user->setEmail("deleted_{$hash}@deleted.local");
        $user->setFirstName('Compte');
        $user->setLastName('Supprimé');
        $user->setPhone(null);
        $user->setAvatarUrl(null);
        $user->setPasswordHash(null);
        $user->setStatus('disabled');
        $user->setUpdatedAt(new \DateTimeImmutable());

        if ($user->getSeller()) {
            foreach ($user->getSeller()->getAnimals() as $animal) {
                if ($animal->getStatus() !== 'sold') {
                    $animal->setStatus('archived');
                }
            }
        }

        $authService->revokeAllRefreshTokens($user);
        $em->flush();

        $audit->log('user.deleted', 'User', $id, $admin);

        return $this->noContent();
    }

    private function serializeUserSummary(User $u): array
    {
        return [
            'id'         => $u->getId(),
            'email'      => $u->getEmail(),
            'first_name' => $u->getFirstName(),
            'last_name'  => $u->getLastName(),
            'status'     => $u->getStatus(),
            'roles'      => $u->getRoles(),
            'seller'     => $u->getSeller() ? ['verified_status' => $u->getSeller()->getVerifiedStatus()] : null,
            'created_at' => $u->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeUserFull(User $u): array
    {
        $data = $this->serializeUserSummary($u);
        $data['is_verified']   = $u->isEmailVerified();
        $data['last_login_at'] = $u->getLastLoginAt()?->format(\DateTimeInterface::ATOM);

        if ($u->getSeller()) {
            $data['seller'] = [
                'id'              => $u->getSeller()->getId(),
                'name'            => $u->getSeller()->getName(),
                'type'            => $u->getSeller()->getType(),
                'verified_status' => $u->getSeller()->getVerifiedStatus(),
                'animals_count'   => $u->getSeller()->getAnimals()->count(),
            ];
        }

        return $data;
    }
}
