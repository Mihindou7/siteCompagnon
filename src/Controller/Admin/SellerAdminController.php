<?php

namespace App\Controller\Admin;

use App\Controller\AbstractApiController;
use App\DTO\Admin\RejectDTO;
use App\Entity\Seller;
use App\Entity\User;
use App\Repository\SellerRepository;
use App\Service\AuditService;
use App\Service\MailService;
use App\Service\PaginationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/sellers')]
#[IsGranted('ROLE_ADMIN')]
class SellerAdminController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        Request $request,
        SellerRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $page   = (int) $request->query->get('page', 1);
        $limit  = (int) $request->query->get('limit', 20);
        $status = $request->query->get('verified_status', 'pending');

        $qb = $repo->createQueryBuilder('s')
            ->leftJoin('s.user', 'u')
            ->addSelect('u')
            ->orderBy('s.createdAt', 'ASC'); // oldest first for moderation queue

        if ($status) {
            $qb->where('s.verifiedStatus = :status')->setParameter('status', $status);
        }

        $result = $paginator->paginate($qb, $page, $limit);
        $result['data'] = array_map(fn(Seller $s) => $this->serializeSeller($s), $result['data']);

        return $this->json($result);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id, SellerRepository $repo, \App\Repository\ReviewRepository $reviewRepo): JsonResponse
    {
        $seller = $repo->find($id);
        if (!$seller) return $this->error('Seller not found.', 404);

        $ratingResult = $reviewRepo->createQueryBuilder('r')
            ->select('AVG(r.rating) as avg_rating, COUNT(r.id) as total')
            ->where('r.seller = :seller')
            ->andWhere('r.status = :status')
            ->setParameter('seller', $seller)
            ->setParameter('status', 'published')
            ->getQuery()
            ->getSingleResult();

        $data                   = $this->serializeSeller($seller);
        $data['rejection_reason'] = $seller->getRejectionReason();
        $data['description']    = $seller->getDescription();
        $data['address']        = $seller->getAddress();
        $data['postal_code']    = $seller->getPostalCode();
        $data['animals_count']  = $seller->getAnimals()->count();
        $data['rating']         = $ratingResult['avg_rating'] ? round((float) $ratingResult['avg_rating'], 1) : null;
        $data['reviews_count']  = (int) $ratingResult['total'];
        $data['user']           = [
            'id'         => $seller->getUser()->getId(),
            'email'      => $seller->getUser()->getEmail(),
            'first_name' => $seller->getUser()->getFirstName(),
        ];

        return $this->success($data);
    }

    #[Route('/{id}/approve', methods: ['PATCH'])]
    public function approve(
        int $id,
        #[CurrentUser] User $admin,
        SellerRepository $repo,
        EntityManagerInterface $em,
        MailService $mailService,
        AuditService $audit,
    ): JsonResponse {
        $seller = $repo->find($id);
        if (!$seller) return $this->error('Seller not found.', 404);

        $seller->setVerifiedStatus('approved');
        $seller->setRejectionReason(null);
        $seller->setUpdatedAt(new \DateTimeImmutable());

        // Grant ROLE_SELLER to user
        $user = $seller->getUser();
        $roles = $user->getRoles();
        if (!in_array('ROLE_SELLER', $roles, true)) {
            $roles[] = 'ROLE_SELLER';
            $user->setRoles(array_values(array_unique($roles)));
        }

        $em->flush();

        $mailService->sendSellerApproved($user->getEmail(), $seller->getName());
        $audit->log('seller.approved', 'Seller', $seller->getId(), $admin);

        return $this->success(['id' => $seller->getId(), 'verified_status' => 'approved']);
    }

    #[Route('/{id}/reject', methods: ['PATCH'])]
    public function reject(
        int $id,
        #[CurrentUser] User $admin,
        #[MapRequestPayload] RejectDTO $dto,
        SellerRepository $repo,
        EntityManagerInterface $em,
        MailService $mailService,
        AuditService $audit,
    ): JsonResponse {
        $seller = $repo->find($id);
        if (!$seller) return $this->error('Seller not found.', 404);

        $seller->setVerifiedStatus('rejected');
        $seller->setRejectionReason($dto->rejectionReason);
        $seller->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $mailService->sendSellerRejected($seller->getUser()->getEmail(), $seller->getName(), $dto->rejectionReason);
        $audit->log('seller.rejected', 'Seller', $seller->getId(), $admin);

        return $this->success(['id' => $seller->getId(), 'verified_status' => 'rejected']);
    }

    private function serializeSeller(Seller $s): array
    {
        return [
            'id'              => $s->getId(),
            'name'            => $s->getName(),
            'type'            => $s->getType(),
            'siret'           => $s->getSiret(),
            'city'            => $s->getCity(),
            'verified_status' => $s->getVerifiedStatus(),
            'user'            => ['email' => $s->getUser()->getEmail(), 'id' => $s->getUser()->getId()],
            'created_at'      => $s->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
