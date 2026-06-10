<?php

namespace App\Controller\Seller;

use App\Controller\AbstractApiController;
use App\DTO\Seller\CreateAnimalDTO;
use App\Entity\Animal;
use App\Entity\AnimalDocument;
use App\Entity\AnimalMedia;
use App\Entity\User;
use App\Repository\AnimalRepository;
use App\Repository\BreedRepository;
use App\Repository\SpeciesRepository;
use App\Service\PaginationService;
use App\Service\UploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/seller/animals')]
#[IsGranted('ROLE_SELLER')]
class AnimalSellerController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
        Request $request,
        AnimalRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $seller = $this->requireSeller($user);
        if ($seller instanceof JsonResponse) return $seller;

        $page   = (int) $request->query->get('page', 1);
        $limit  = (int) $request->query->get('limit', 20);
        $status = $request->query->get('status');

        $qb = $repo->createQueryBuilder('a')
            ->leftJoin('a.species', 'sp')
            ->leftJoin('a.breed', 'b')
            ->leftJoin('a.media', 'm', 'WITH', 'm.isCover = true')
            ->addSelect('sp', 'b', 'm')
            ->where('a.seller = :seller')
            ->setParameter('seller', $seller)
            ->orderBy('a.createdAt', 'DESC');

        if ($status) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        $result = $paginator->paginate($qb, $page, $limit);
        $result['data'] = array_map(fn(Animal $a) => $this->serializeList($a), $result['data']);

        return $this->json($result);
    }

    #[Route('/{id}', methods: ['GET'])]
    public function show(
        int $id,
        #[CurrentUser] User $user,
        AnimalRepository $repo,
    ): JsonResponse {
        [$animal, $err] = $this->findOwnedAnimal($id, $user, $repo);
        if ($err) return $err;

        return $this->success($this->serializeDetail($animal));
    }

    #[Route('', methods: ['POST'])]
    public function create(
        #[CurrentUser] User $user,
        #[MapRequestPayload] CreateAnimalDTO $dto,
        SpeciesRepository $speciesRepo,
        BreedRepository $breedRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $seller = $this->requireSeller($user);
        if ($seller instanceof JsonResponse) return $seller;

        if (!$seller->isApproved()) {
            return $this->error('Seller account not approved.', 403);
        }

        $species = $speciesRepo->find($dto->speciesId);
        if (!$species) {
            return $this->error('Species not found.', 404);
        }

        $animal = new Animal();
        $animal->setSeller($seller);
        $animal->setSpecies($species);
        $animal->setTitle($dto->title);
        $animal->setDescription($dto->description);
        $animal->setSex($dto->sex);
        $animal->setPrice((string) $dto->price);
        $animal->setCity($dto->city);
        $animal->setPostalCode($dto->postalCode);
        $animal->setName($dto->name);
        $animal->setStatus('pending_review');

        if ($dto->breedId) {
            $breed = $breedRepo->find($dto->breedId);
            if ($breed) $animal->setBreed($breed);
        }
        if ($dto->birthdate) {
            $animal->setBirthdate(new \DateTimeImmutable($dto->birthdate));
        }

        $em->persist($animal);
        $em->flush();

        return $this->created([
            'id'      => $animal->getId(),
            'status'  => $animal->getStatus(),
            'message' => 'Votre annonce a été soumise. Elle sera visible après validation par notre équipe.',
        ]);
    }

    #[Route('/{id}', methods: ['PATCH'])]
    public function update(
        int $id,
        #[CurrentUser] User $user,
        Request $request,
        AnimalRepository $repo,
        BreedRepository $breedRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        [$animal, $err] = $this->findOwnedAnimal($id, $user, $repo);
        if ($err) return $err;

        if (in_array($animal->getStatus(), ['reserved', 'sold', 'archived'], true)) {
            return $this->error('Cannot edit an animal with status: ' . $animal->getStatus() . '.', 409);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        if (isset($data['title']))       $animal->setTitle($data['title']);
        if (isset($data['description'])) $animal->setDescription($data['description']);
        if (isset($data['price']))       $animal->setPrice((string) $data['price']);
        if (isset($data['city']))        $animal->setCity($data['city']);
        if (isset($data['postal_code'])) $animal->setPostalCode($data['postal_code']);
        if (isset($data['sex']))         $animal->setSex($data['sex']);
        if (isset($data['birthdate']))   $animal->setBirthdate(new \DateTimeImmutable($data['birthdate']));
        if (isset($data['breed_id'])) {
            $breed = $breedRepo->find($data['breed_id']);
            if ($breed) $animal->setBreed($breed);
        }

        $requiresRemoderation = $animal->getStatus() === 'published';
        if ($requiresRemoderation) {
            $animal->setStatus('pending_review');
        }

        $animal->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->success([
            'id'                    => $animal->getId(),
            'status'                => $animal->getStatus(),
            'requires_remoderation' => $requiresRemoderation,
            'message'               => $requiresRemoderation
                ? 'Vos modifications ont été enregistrées. Votre annonce est repassée en attente de validation et temporairement masquée.'
                : 'Modifications enregistrées.',
        ]);
    }

    #[Route('/{id}', methods: ['DELETE'])]
    public function delete(
        int $id,
        #[CurrentUser] User $user,
        AnimalRepository $repo,
        EntityManagerInterface $em,
    ): JsonResponse {
        [$animal, $err] = $this->findOwnedAnimal($id, $user, $repo);
        if ($err) return $err;

        if ($animal->getStatus() === 'reserved') {
            return $this->error('Cannot archive a reserved animal. Handle the reservation first.', 409);
        }

        $animal->setStatus('archived');
        $animal->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->noContent();
    }

    // ─── Media ────────────────────────────────────────────────────────────────

    #[Route('/{id}/media', methods: ['POST'])]
    public function uploadMedia(
        int $id,
        #[CurrentUser] User $user,
        Request $request,
        AnimalRepository $repo,
        UploadService $uploadService,
        EntityManagerInterface $em,
    ): JsonResponse {
        [$animal, $err] = $this->findOwnedAnimal($id, $user, $repo);
        if ($err) return $err;

        if ($animal->getMedia()->count() >= 10) {
            return $this->error('Maximum 10 photos per listing.', 409);
        }

        $file = $request->files->get('photo');
        if (!$file) {
            return $this->error('No file uploaded.', 400);
        }

        $url      = $uploadService->uploadAnimalMedia($file);
        $isCover  = filter_var($request->request->get('is_cover', false), FILTER_VALIDATE_BOOLEAN);
        $position = (int) $request->request->get('position', $animal->getMedia()->count());

        // Remove existing cover if new one is set
        if ($isCover) {
            foreach ($animal->getMedia() as $existing) {
                if ($existing->isCover()) {
                    $existing->setIsCover(false);
                }
            }
        }

        $media = new AnimalMedia();
        $media->setAnimal($animal);
        $media->setFileUrl($url);
        $media->setOriginalName($file->getClientOriginalName());
        $media->setMimeType($file->getMimeType() ?? 'image/jpeg');
        $media->setPosition($position);
        $media->setIsCover($isCover);
        $em->persist($media);
        $em->flush();

        return $this->created([
            'id'       => $media->getId(),
            'file_url' => $media->getFileUrl(),
            'is_cover' => $media->isCover(),
            'position' => $media->getPosition(),
        ]);
    }

    #[Route('/{id}/media/{mediaId}', methods: ['DELETE'])]
    public function deleteMedia(
        int $id,
        int $mediaId,
        #[CurrentUser] User $user,
        AnimalRepository $repo,
        UploadService $uploadService,
        EntityManagerInterface $em,
    ): JsonResponse {
        [$animal, $err] = $this->findOwnedAnimal($id, $user, $repo);
        if ($err) return $err;

        $media = null;
        foreach ($animal->getMedia() as $m) {
            if ($m->getId() === $mediaId) {
                $media = $m;
                break;
            }
        }

        if (!$media) {
            return $this->error('Media not found.', 404);
        }

        if ($animal->getStatus() === 'published' && $animal->getMedia()->count() === 1) {
            return $this->error('Cannot delete the only photo of a published listing.', 409);
        }

        $wasCover = $media->isCover();
        $uploadService->delete($media->getFileUrl());
        $em->remove($media);
        $em->flush();

        // Promote next media as cover
        if ($wasCover && $animal->getMedia()->count() > 0) {
            $first = $animal->getMedia()->first();
            if ($first) {
                $first->setIsCover(true);
                $em->flush();
            }
        }

        return $this->noContent();
    }

    // ─── Documents ───────────────────────────────────────────────────────────

    #[Route('/{id}/documents', methods: ['POST'])]
    public function uploadDocument(
        int $id,
        #[CurrentUser] User $user,
        Request $request,
        AnimalRepository $repo,
        UploadService $uploadService,
        EntityManagerInterface $em,
    ): JsonResponse {
        [$animal, $err] = $this->findOwnedAnimal($id, $user, $repo);
        if ($err) return $err;

        $file = $request->files->get('document');
        if (!$file) {
            return $this->error('No file uploaded.', 400);
        }

        $type = $request->request->get('type', 'other');
        if (!in_array($type, ['vaccine', 'certificate', 'pedigree', 'other'], true)) {
            return $this->error('Invalid document type.', 422);
        }

        $url      = $uploadService->uploadAnimalDocument($file);
        $isPublic = filter_var($request->request->get('is_public', false), FILTER_VALIDATE_BOOLEAN);

        $doc = new AnimalDocument();
        $doc->setAnimal($animal);
        $doc->setType($type);
        $doc->setFileUrl($url);
        $doc->setOriginalName($file->getClientOriginalName());
        $doc->setMimeType($file->getMimeType() ?? 'application/pdf');
        $doc->setIsPublic($isPublic);
        $em->persist($doc);
        $em->flush();

        return $this->created([
            'id'            => $doc->getId(),
            'type'          => $doc->getType(),
            'original_name' => $doc->getOriginalName(),
            'is_public'     => $doc->isPublic(),
        ]);
    }

    #[Route('/{id}/documents/{docId}', methods: ['DELETE'])]
    public function deleteDocument(
        int $id,
        int $docId,
        #[CurrentUser] User $user,
        AnimalRepository $repo,
        UploadService $uploadService,
        EntityManagerInterface $em,
    ): JsonResponse {
        [$animal, $err] = $this->findOwnedAnimal($id, $user, $repo);
        if ($err) return $err;

        $doc = null;
        foreach ($animal->getDocuments() as $d) {
            if ($d->getId() === $docId) {
                $doc = $d;
                break;
            }
        }

        if (!$doc) {
            return $this->error('Document not found.', 404);
        }

        $uploadService->delete($doc->getFileUrl());
        $em->remove($doc);
        $em->flush();

        return $this->noContent();
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function requireSeller(User $user): mixed
    {
        $seller = $user->getSeller();
        return $seller ?? $this->error('No seller profile.', 403);
    }

    private function findOwnedAnimal(int $id, User $user, AnimalRepository $repo): array
    {
        $seller = $user->getSeller();
        $animal = $repo->find($id);

        if (!$animal || !$seller || $animal->getSeller()->getId() !== $seller->getId()) {
            return [null, $this->error('Animal not found.', 404)];
        }

        return [$animal, null];
    }

    private function serializeList(Animal $a): array
    {
        $cover = null;
        foreach ($a->getMedia() as $m) {
            if ($m->isCover()) { $cover = $m->getFileUrl(); break; }
        }

        return [
            'id'                         => $a->getId(),
            'title'                      => $a->getTitle(),
            'species'                    => ['id' => $a->getSpecies()->getId(), 'name' => $a->getSpecies()->getName()],
            'breed'                      => $a->getBreed() ? ['id' => $a->getBreed()->getId(), 'name' => $a->getBreed()->getName()] : null,
            'status'                     => $a->getStatus(),
            'price'                      => (float) $a->getPrice(),
            'city'                       => $a->getCity(),
            'sex'                        => $a->getSex(),
            'cover_url'                  => $cover,
            'pending_reservations_count' => count(array_filter($a->getReservations()->toArray(), fn($r) => $r->getStatus() === 'pending')),
            'published_at'               => $a->getPublishedAt()?->format(\DateTimeInterface::ATOM),
            'created_at'                 => $a->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    private function serializeDetail(Animal $a): array
    {
        $data              = $this->serializeList($a);
        $data['description'] = $a->getDescription();
        $data['birthdate']   = $a->getBirthdate()?->format('Y-m-d');
        $data['postal_code'] = $a->getPostalCode();

        $data['media'] = array_map(fn($m) => [
            'id'       => $m->getId(),
            'file_url' => $m->getFileUrl(),
            'is_cover' => $m->isCover(),
            'position' => $m->getPosition(),
        ], $a->getMedia()->toArray());

        $data['documents'] = array_map(fn($d) => [
            'id'            => $d->getId(),
            'type'          => $d->getType(),
            'original_name' => $d->getOriginalName(),
            'is_public'     => $d->isPublic(),
        ], $a->getDocuments()->toArray());

        return $data;
    }
}
