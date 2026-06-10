<?php

namespace App\Controller\Admin;

use App\Controller\AbstractApiController;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\ReviewRepository;
use App\Service\AuditService;
use App\Service\PaginationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/admin/reviews')]
#[IsGranted('ROLE_ADMIN')]
class ReviewAdminController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        Request $request,
        ReviewRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $page   = (int) $request->query->get('page', 1);
        $limit  = (int) $request->query->get('limit', 20);
        $status = $request->query->get('status', 'pending');

        $qb = $repo->createQueryBuilder('r')
            ->leftJoin('r.seller', 's')
            ->leftJoin('r.buyer', 'b')
            ->addSelect('s', 'b')
            ->orderBy('r.createdAt', 'ASC');

        if ($status) {
            $qb->where('r.status = :status')->setParameter('status', $status);
        }

        $result = $paginator->paginate($qb, $page, $limit);
        $result['data'] = array_map(fn(Review $r) => [
            'id'      => $r->getId(),
            'rating'  => $r->getRating(),
            'comment' => $r->getComment(),
            'status'  => $r->getStatus(),
            'seller'  => ['name' => $r->getSeller()->getName()],
            'buyer'   => ['email' => $r->getBuyer()->getEmail()],
            'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $result['data']);

        return $this->json($result);
    }

    #[Route('/{id}/toggle-visibility', methods: ['PATCH'])]
    public function toggleVisibility(
        int $id,
        #[CurrentUser] User $admin,
        ReviewRepository $repo,
        EntityManagerInterface $em,
        AuditService $audit,
    ): JsonResponse {
        $review = $repo->find($id);
        if (!$review) return $this->error('Review not found.', 404);

        $newStatus = $review->getStatus() === 'published' ? 'hidden' : 'published';
        $review->setStatus($newStatus);
        $review->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        [$sellerRating, $reviewsCount] = $this->calculateSellerRating($review->getSeller()->getId(), $repo);

        $audit->log(
            $newStatus === 'published' ? 'review.published' : 'review.hidden',
            'Review',
            $review->getId(),
            $admin,
            ['status' => $review->getStatus()],
            ['status' => $newStatus]
        );

        return $this->success([
            'id'                    => $review->getId(),
            'status'                => $newStatus,
            'seller_rating_updated' => $sellerRating,
            'seller_reviews_count'  => $reviewsCount,
        ]);
    }

    private function calculateSellerRating(int $sellerId, ReviewRepository $repo): array
    {
        $result = $repo->createQueryBuilder('r')
            ->select('AVG(r.rating) as avg_rating, COUNT(r.id) as total')
            ->where('r.seller = :seller')
            ->andWhere('r.status = :status')
            ->setParameter('seller', $sellerId)
            ->setParameter('status', 'published')
            ->getQuery()
            ->getSingleResult();

        return [
            $result['avg_rating'] ? round((float) $result['avg_rating'], 1) : 0.0,
            (int) $result['total'],
        ];
    }
}
