<?php

namespace App\Controller\User;

use App\Controller\AbstractApiController;
use App\DTO\Review\CreateReviewDTO;
use App\Entity\Review;
use App\Entity\User;
use App\Repository\ReservationRepository;
use App\Repository\ReviewRepository;
use App\Service\PaginationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/me/reviews')]
#[IsGranted('ROLE_USER')]
class ReviewController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
        Request $request,
        ReviewRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $page  = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 20);

        $qb = $repo->createQueryBuilder('r')
            ->leftJoin('r.seller', 's')
            ->addSelect('s')
            ->where('r.buyer = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC');

        $result = $paginator->paginate($qb, $page, $limit);
        $result['data'] = array_map(fn(Review $r) => [
            'id'         => $r->getId(),
            'rating'     => $r->getRating(),
            'comment'    => $r->getComment(),
            'status'     => $r->getStatus(),
            'seller'     => ['name' => $r->getSeller()->getName()],
            'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $result['data']);

        return $this->json($result);
    }

    #[Route('', methods: ['POST'])]
    public function create(
        #[CurrentUser] User $user,
        #[MapRequestPayload] CreateReviewDTO $dto,
        ReservationRepository $reservationRepo,
        ReviewRepository $reviewRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$user->isEmailVerified()) {
            return $this->error('Email verification required.', 403);
        }

        $reservation = $reservationRepo->find($dto->reservationId);
        if (!$reservation || $reservation->getBuyer()->getId() !== $user->getId()) {
            return $this->error('Reservation not found.', 403);
        }
        if ($reservation->getStatus() !== 'completed') {
            return $this->error('Reviews can only be submitted after a completed reservation.', 422);
        }

        $existing = $reviewRepo->findOneBy(['reservation' => $reservation]);
        if ($existing) {
            return $this->error('A review already exists for this reservation.', 409);
        }

        $review = new Review();
        $review->setSeller($reservation->getSeller());
        $review->setBuyer($user);
        $review->setReservation($reservation);
        $review->setRating($dto->rating);
        $review->setComment($dto->comment);
        $review->setStatus('pending');
        $em->persist($review);
        $em->flush();

        return $this->created([
            'id'     => $review->getId(),
            'rating' => $review->getRating(),
            'status' => $review->getStatus(),
        ]);
    }
}
