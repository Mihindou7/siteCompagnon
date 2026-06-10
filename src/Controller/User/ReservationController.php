<?php

namespace App\Controller\User;

use App\Controller\AbstractApiController;
use App\DTO\Reservation\CreateReservationDTO;
use App\Entity\Reservation;
use App\Entity\User;
use App\Repository\AnimalRepository;
use App\Repository\ReservationRepository;
use App\Service\MailService;
use App\Service\PaginationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/me/reservations')]
#[IsGranted('ROLE_USER')]
class ReservationController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
        Request $request,
        ReservationRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $page   = (int) $request->query->get('page', 1);
        $limit  = (int) $request->query->get('limit', 20);
        $status = $request->query->get('status');

        $qb = $repo->createQueryBuilder('r')
            ->leftJoin('r.animal', 'a')
            ->leftJoin('r.seller', 's')
            ->addSelect('a', 's')
            ->where('r.buyer = :user')
            ->setParameter('user', $user)
            ->orderBy('r.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        $result = $paginator->paginate($qb, $page, $limit);
        $result['data'] = array_map(fn(Reservation $r) => $this->serializeReservation($r), $result['data']);

        return $this->json($result);
    }

    #[Route('', methods: ['POST'])]
    public function create(
        #[CurrentUser] User $user,
        #[MapRequestPayload] CreateReservationDTO $dto,
        AnimalRepository $animalRepo,
        ReservationRepository $reservationRepo,
        EntityManagerInterface $em,
        MailService $mailService,
    ): JsonResponse {
        if (!$user->isEmailVerified()) {
            return $this->error('Email verification required.', 403);
        }

        $animal = $animalRepo->find($dto->animalId);
        if (!$animal || $animal->getStatus() !== 'published') {
            return $this->error('Animal not found or not available.', 404);
        }

        // Check no accepted reservation exists
        $accepted = $reservationRepo->findOneBy(['animal' => $animal, 'status' => 'accepted']);
        if ($accepted) {
            return $this->error('This animal is already reserved.', 409);
        }

        // Check user has no pending reservation
        $pending = $reservationRepo->findOneBy(['animal' => $animal, 'buyer' => $user, 'status' => 'pending']);
        if ($pending) {
            return $this->error('You already have a pending reservation for this animal.', 409);
        }

        $reservation = new Reservation();
        $reservation->setAnimal($animal);
        $reservation->setBuyer($user);
        $reservation->setSeller($animal->getSeller());
        $reservation->setMessage($dto->message);
        $reservation->setStatus('pending');
        $em->persist($reservation);
        $em->flush();

        $mailService->sendReservationCreated($animal->getSeller()->getUser()->getEmail(), [
            'animal_title' => $animal->getTitle(),
            'buyer_message' => $dto->message,
        ]);

        return $this->created([
            'id'         => $reservation->getId(),
            'status'     => $reservation->getStatus(),
            'animal'     => ['id' => $animal->getId(), 'title' => $animal->getTitle()],
            'created_at' => $reservation->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(
        int $id,
        #[CurrentUser] User $user,
        ReservationRepository $repo,
    ): JsonResponse {
        $reservation = $repo->find($id);
        if (!$reservation) {
            return $this->error('Reservation not found.', 404);
        }
        if ($reservation->getBuyer()->getId() !== $user->getId()) {
            return $this->error('Access denied.', 403);
        }

        return $this->success($this->serializeReservation($reservation));
    }

    #[Route('/{id}/cancel', methods: ['PATCH'])]
    public function cancel(
        int $id,
        #[CurrentUser] User $user,
        ReservationRepository $repo,
        EntityManagerInterface $em,
        MailService $mailService,
    ): JsonResponse {
        $reservation = $repo->find($id);
        if (!$reservation) {
            return $this->error('Reservation not found.', 404);
        }
        if ($reservation->getBuyer()->getId() !== $user->getId()) {
            return $this->error('Access denied.', 403);
        }
        if ($reservation->getStatus() !== 'pending') {
            return $this->error('Only pending reservations can be cancelled.', 409);
        }

        $reservation->setStatus('cancelled');
        $reservation->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $mailService->sendReservationCancelled($reservation->getSeller()->getUser()->getEmail(), [
            'animal_title' => $reservation->getAnimal()->getTitle(),
            'buyer_name'   => $user->getFirstName() ?? $user->getEmail(),
        ]);

        return $this->success(['status' => 'cancelled']);
    }

    private function serializeReservation(Reservation $r): array
    {
        return [
            'id'              => $r->getId(),
            'status'          => $r->getStatus(),
            'message'         => $r->getMessage(),
            'seller_response' => $r->getSellerResponse(),
            'animal'          => [
                'id'    => $r->getAnimal()->getId(),
                'title' => $r->getAnimal()->getTitle(),
            ],
            'seller' => [
                'name' => $r->getSeller()->getName(),
                'city' => $r->getSeller()->getCity(),
            ],
            'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
