<?php

namespace App\Controller\User;

use App\Controller\AbstractApiController;
use App\Entity\Animal;
use App\Entity\Favorite;
use App\Entity\User;
use App\Repository\AnimalRepository;
use App\Repository\FavoriteRepository;
use App\Service\PaginationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/me/favorites')]
#[IsGranted('ROLE_USER')]
class FavoriteController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        #[CurrentUser] User $user,
        Request $request,
        FavoriteRepository $repo,
        PaginationService $paginator,
    ): JsonResponse {
        $page  = (int) $request->query->get('page', 1);
        $limit = (int) $request->query->get('limit', 20);

        $qb = $repo->createQueryBuilder('f')
            ->leftJoin('f.animal', 'a')
            ->leftJoin('a.media', 'm', 'WITH', 'm.isCover = true')
            ->addSelect('a', 'm')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', 'DESC');

        $result = $paginator->paginate($qb, $page, $limit);

        $result['data'] = array_map(fn(Favorite $f) => [
            'id'         => $f->getId(),
            'animal'     => [
                'id'        => $f->getAnimal()->getId(),
                'title'     => $f->getAnimal()->getTitle(),
                'price'     => $f->getAnimal()->getPrice(),
                'status'    => $f->getAnimal()->getStatus(),
                'cover_url' => $f->getAnimal()->getMedia()->first()?->getFileUrl(),
            ],
            'created_at' => $f->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $result['data']);

        return $this->json($result);
    }

    #[Route('/{animalId}', methods: ['POST'])]
    public function add(
        int $animalId,
        #[CurrentUser] User $user,
        AnimalRepository $animalRepo,
        FavoriteRepository $favoriteRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $animal = $animalRepo->find($animalId);
        if (!$animal) {
            return $this->error('Animal not found.', 404);
        }

        // Idempotent
        if ($favoriteRepo->findOneBy(['user' => $user, 'animal' => $animal])) {
            return $this->success(['message' => 'Already in favorites']);
        }

        $favorite = new Favorite();
        $favorite->setUser($user);
        $favorite->setAnimal($animal);
        $em->persist($favorite);
        $em->flush();

        return $this->success(['message' => 'Ajouté aux favoris']);
    }

    #[Route('/{animalId}', methods: ['DELETE'])]
    public function remove(
        int $animalId,
        #[CurrentUser] User $user,
        AnimalRepository $animalRepo,
        FavoriteRepository $favoriteRepo,
        EntityManagerInterface $em,
    ): JsonResponse {
        $animal = $animalRepo->find($animalId);
        if (!$animal) {
            return $this->noContent(); // idempotent
        }

        $favorite = $favoriteRepo->findOneBy(['user' => $user, 'animal' => $animal]);
        if ($favorite) {
            $em->remove($favorite);
            $em->flush();
        }

        return $this->noContent();
    }
}
