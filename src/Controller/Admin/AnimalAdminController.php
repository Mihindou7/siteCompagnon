<?php

namespace App\Controller\Admin;

use App\Controller\AbstractApiController;
use App\DTO\Admin\RejectDTO;
use App\Entity\Animal;
use App\Entity\User;
use App\Repository\AnimalRepository;
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

#[Route('/api/admin/animals')]
#[IsGranted('ROLE_ADMIN')]
class AnimalAdminController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        Request $request,
        AnimalRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $page   = (int) $request->query->get('page', 1);
        $limit  = (int) $request->query->get('limit', 20);
        $status = $request->query->get('status', 'pending_review');

        $sellerId = $request->query->get('seller_id');

        $qb = $repo->createQueryBuilder('a')
            ->leftJoin('a.seller', 's')
            ->leftJoin('a.species', 'sp')
            ->leftJoin('a.breed', 'b')
            ->leftJoin('a.media', 'm', 'WITH', 'm.isCover = true')
            ->addSelect('s', 'sp', 'b', 'm')
            ->orderBy('a.createdAt', 'ASC');

        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }
        if ($sellerId) {
            $qb->andWhere('a.seller = :seller')->setParameter('seller', (int) $sellerId);
        }

        $result = $paginator->paginate($qb, $page, $limit);
        $result['data'] = array_map(fn(Animal $a) => [
            'id'          => $a->getId(),
            'title'       => $a->getTitle(),
            'description' => $a->getDescription(),
            'status'      => $a->getStatus(),
            'price'       => (float) $a->getPrice(),
            'city'        => $a->getCity(),
            'species'     => ['id' => $a->getSpecies()->getId(), 'name' => $a->getSpecies()->getName()],
            'breed'       => $a->getBreed() ? ['id' => $a->getBreed()->getId(), 'name' => $a->getBreed()->getName()] : null,
            'cover_url'   => $a->getMedia()->first() ? $a->getMedia()->first()->getFileUrl() : null,
            'media_count' => $a->getMedia()->count(),
            'seller'      => ['id' => $a->getSeller()->getId(), 'name' => $a->getSeller()->getName(), 'verified_status' => $a->getSeller()->getVerifiedStatus()],
            'created_at'  => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $result['data']);

        return $this->json($result);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(int $id, AnimalRepository $repo): JsonResponse
    {
        $animal = $repo->find($id);
        if (!$animal) return $this->error('Animal not found.', 404);

        return $this->success([
            'id'          => $animal->getId(),
            'title'       => $animal->getTitle(),
            'description' => $animal->getDescription(),
            'status'      => $animal->getStatus(),
            'price'       => (float) $animal->getPrice(),
            'sex'         => $animal->getSex(),
            'birthdate'   => $animal->getBirthdate()?->format('Y-m-d'),
            'city'        => $animal->getCity(),
            'postal_code' => $animal->getPostalCode(),
            'species'     => ['id' => $animal->getSpecies()->getId(), 'name' => $animal->getSpecies()->getName()],
            'breed'       => $animal->getBreed() ? ['id' => $animal->getBreed()->getId(), 'name' => $animal->getBreed()->getName()] : null,
            'seller'      => [
                'id'              => $animal->getSeller()->getId(),
                'name'            => $animal->getSeller()->getName(),
                'verified_status' => $animal->getSeller()->getVerifiedStatus(),
            ],
            'media' => array_map(fn($m) => [
                'id'       => $m->getId(),
                'file_url' => $m->getFileUrl(),
                'is_cover' => $m->isCover(),
                'position' => $m->getPosition(),
            ], $animal->getMedia()->toArray()),
            'documents' => array_map(fn($d) => [
                'id'            => $d->getId(),
                'type'          => $d->getType(),
                'original_name' => $d->getOriginalName(),
                'is_public'     => $d->isPublic(),
            ], $animal->getDocuments()->toArray()),
            'published_at' => $animal->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'created_at'   => $animal->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/publish', methods: ['PATCH'])]
    public function publish(
        int $id,
        #[CurrentUser] User $admin,
        AnimalRepository $repo,
        EntityManagerInterface $em,
        MailService $mailService,
        AuditService $audit,
    ): JsonResponse {
        $animal = $repo->find($id);
        if (!$animal) return $this->error('Animal not found.', 404);
        if ($animal->getStatus() !== 'pending_review') {
            return $this->error('Animal is not pending review.', 409);
        }

        $animal->setStatus('published');
        $animal->setPublishedAt(new \DateTimeImmutable());
        $animal->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $mailService->sendAnimalPublished($animal->getSeller()->getUser()->getEmail(), $animal->getTitle());
        $audit->log('animal.published', 'Animal', $animal->getId(), $admin);

        return $this->success([
            'id'           => $animal->getId(),
            'status'       => 'published',
            'published_at' => $animal->getPublishedAt()->format(\DateTimeInterface::ATOM),
        ]);
    }

    #[Route('/{id}/reject', methods: ['PATCH'])]
    public function reject(
        int $id,
        #[CurrentUser] User $admin,
        #[MapRequestPayload] RejectDTO $dto,
        AnimalRepository $repo,
        EntityManagerInterface $em,
        MailService $mailService,
        AuditService $audit,
    ): JsonResponse {
        $animal = $repo->find($id);
        if (!$animal) return $this->error('Animal not found.', 404);
        if ($animal->getStatus() !== 'pending_review') {
            return $this->error('Animal is not pending review.', 409);
        }

        $animal->setStatus('draft');
        $animal->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        $mailService->sendAnimalRejected($animal->getSeller()->getUser()->getEmail(), $animal->getTitle(), $dto->rejectionReason);
        $audit->log('animal.rejected', 'Animal', $animal->getId(), $admin);

        return $this->success(['id' => $animal->getId(), 'status' => 'draft']);
    }
}
