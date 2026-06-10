<?php

namespace App\Controller\Seller;

use App\Controller\AbstractApiController;
use App\DTO\Seller\ReservationResponseDTO;
use App\Entity\Reservation;
use App\Entity\User;
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

#[Route('/api/seller/reservations')]
#[IsGranted('ROLE_SELLER')]
class ReservationSellerController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
        Request $request,
        ReservationRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $seller = $user->getSeller();
        if (!$seller) return $this->error('No seller profile.', 403);

        $page   = (int) $request->query->get('page', 1);
        $limit  = (int) $request->query->get('limit', 20);
        $status = $request->query->get('status');

        $qb = $repo->createQueryBuilder('r')
            ->leftJoin('r.animal', 'a')
            ->leftJoin('a.media', 'm', 'WITH', 'm.isCover = true')
            ->leftJoin('r.buyer', 'b')
            ->addSelect('a', 'm', 'b')
            ->where('r.seller = :seller')
            ->setParameter('seller', $seller)
            ->orderBy('r.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        $result = $paginator->paginate($qb, $page, $limit);
        $result['data'] = array_map(fn(Reservation $r) => $this->serializeList($r), $result['data']);

        return $this->json($result);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(
        int $id,
        #[CurrentUser] User $user,
        ReservationRepository $repo,
    ): JsonResponse {
        [$reservation, $err] = $this->findOwned($id, $user, $repo);
        if ($err) return $err;

        $buyer = $reservation->getBuyer();
        return $this->success([
            'id'              => $reservation->getId(),
            'status'          => $reservation->getStatus(),
            'message'         => $reservation->getMessage(),
            'seller_response' => $reservation->getSellerResponse(),
            'animal'          => [
                'id'    => $reservation->getAnimal()->getId(),
                'title' => $reservation->getAnimal()->getTitle(),
                'price' => (float) $reservation->getAnimal()->getPrice(),
            ],
            'buyer'     => [
                'first_name' => $buyer->getFirstName(),
                'last_name'  => $buyer->getLastName() ? substr($buyer->getLastName(), 0, 1) . '.' : null,
                'phone'      => $buyer->getPhone(),
            ],
            'created_at' => $reservation->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'expires_at' => $reservation->getExpiresAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/accept', methods: ['PATCH'])]
    public function accept(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] ReservationResponseDTO $dto,
        ReservationRepository $repo,
        EntityManagerInterface $em,
        MailService $mailService,
    ): JsonResponse {
        [$reservation, $err] = $this->findOwned($id, $user, $repo);
        if ($err) return $err;

        if ($reservation->getStatus() !== 'pending') {
            return $this->error('Only pending reservations can be accepted.', 409);
        }

        $reservation->setStatus('accepted');
        $reservation->setSellerResponse($dto->sellerResponse);
        $reservation->setUpdatedAt(new \DateTimeImmutable());

        $animal = $reservation->getAnimal();
        $animal->setStatus('reserved');
        $animal->setUpdatedAt(new \DateTimeImmutable());

        // Auto-reject all other pending reservations on same animal
        $others = $repo->findBy(['animal' => $animal, 'status' => 'pending']);
        $autoRejectedCount = 0;
        foreach ($others as $other) {
            if ($other->getId() !== $reservation->getId()) {
                $other->setStatus('rejected');
                $other->setUpdatedAt(new \DateTimeImmutable());
                $autoRejectedCount++;
                $mailService->sendReservationRejected($other->getBuyer()->getEmail(), [
                    'animal_title'    => $animal->getTitle(),
                    'seller_response' => null,
                ]);
            }
        }

        $em->flush();

        $mailService->sendReservationAccepted($reservation->getBuyer()->getEmail(), [
            'animal_title'    => $animal->getTitle(),
            'seller_response' => $dto->sellerResponse,
        ]);

        return $this->success([
            'status'              => 'accepted',
            'auto_rejected_count' => $autoRejectedCount,
        ]);
    }

    #[Route('/{id}/reject', methods: ['PATCH'])]
    public function reject(
        int $id,
        #[CurrentUser] User $user,
        #[MapRequestPayload] ReservationResponseDTO $dto,
        ReservationRepository $repo,
        EntityManagerInterface $em,
        MailService $mailService,
    ): JsonResponse {
        [$reservation, $err] = $this->findOwned($id, $user, $repo);
        if ($err) return $err;

        if ($reservation->getStatus() !== 'pending') {
            return $this->error('Only pending reservations can be rejected.', 409);
        }

        $reservation->setStatus('rejected');
        $reservation->setSellerResponse($dto->sellerResponse);
        $reservation->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $mailService->sendReservationRejected($reservation->getBuyer()->getEmail(), [
            'animal_title'    => $reservation->getAnimal()->getTitle(),
            'seller_response' => $dto->sellerResponse,
        ]);

        return $this->success(['status' => 'rejected']);
    }

    #[Route('/{id}/complete', methods: ['PATCH'])]
    public function complete(
        int $id,
        #[CurrentUser] User $user,
        ReservationRepository $repo,
        EntityManagerInterface $em,
        MailService $mailService,
    ): JsonResponse {
        [$reservation, $err] = $this->findOwned($id, $user, $repo);
        if ($err) return $err;

        if ($reservation->getStatus() !== 'accepted') {
            return $this->error('Only accepted reservations can be completed.', 409);
        }

        $reservation->setStatus('completed');
        $reservation->setUpdatedAt(new \DateTimeImmutable());

        $animal = $reservation->getAnimal();
        $animal->setStatus('sold');
        $animal->setUpdatedAt(new \DateTimeImmutable());

        $em->flush();

        $mailService->sendReservationCompleted($reservation->getBuyer()->getEmail(), [
            'animal_title' => $animal->getTitle(),
        ]);

        return $this->success(['status' => 'completed']);
    }

    private function findOwned(int $id, User $user, ReservationRepository $repo): array
    {
        $seller      = $user->getSeller();
        $reservation = $repo->find($id);

        if (!$reservation || !$seller || $reservation->getSeller()->getId() !== $seller->getId()) {
            return [null, $this->error('Reservation not found.', 404)];
        }

        return [$reservation, null];
    }

    private function serializeList(Reservation $r): array
    {
        $cover = null;
        foreach ($r->getAnimal()->getMedia() as $m) {
            if ($m->isCover()) { $cover = $m->getFileUrl(); break; }
        }

        return [
            'id'         => $r->getId(),
            'status'     => $r->getStatus(),
            'message'    => $r->getMessage(),
            'animal'     => [
                'id'        => $r->getAnimal()->getId(),
                'title'     => $r->getAnimal()->getTitle(),
                'cover_url' => $cover,
            ],
            'buyer'      => [
                'first_name' => $r->getBuyer()->getFirstName(),
            ],
            'created_at' => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $r->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
