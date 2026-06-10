<?php

namespace App\Controller\Public;

use App\Controller\AbstractApiController;
use App\Entity\Breed;
use App\Repository\AnimalRepository;
use App\Repository\BreedRepository;
use App\Repository\SpeciesRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/breeds')]
class BreedController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        Request $request,
        BreedRepository $repo,
        SpeciesRepository $speciesRepo,
        AnimalRepository $animalRepo,
    ): JsonResponse {
        $speciesId   = $request->query->get('species_id');
        $speciesSlug = $request->query->get('species_slug');

        $qb = $repo->createQueryBuilder('b')
            ->leftJoin('b.species', 's')
            ->addSelect('s')
            ->orderBy('b.name', 'ASC');

        if ($speciesId) {
            $qb->where('b.species = :sid')->setParameter('sid', (int) $speciesId);
        } elseif ($speciesSlug) {
            $species = $speciesRepo->findOneBy(['slug' => $speciesSlug]);
            if ($species) {
                $qb->where('b.species = :sid')->setParameter('sid', $species->getId());
            }
        }

        $breeds = $qb->getQuery()->getResult();

        $data = array_map(fn(Breed $b) => [
            ...$this->serializeBreed($b),
            'available_animals_count' => $animalRepo->countPublishedByBreed($b->getId()),
        ], $breeds);

        return $this->success($data);
    }

    #[Route('/{slug}', methods: ['GET'])]
    public function show(
        string $slug,
        BreedRepository $repo,
        AnimalRepository $animalRepo,
    ): JsonResponse {
        $breed = $repo->createQueryBuilder('b')
            ->leftJoin('b.species', 's')
            ->addSelect('s')
            ->where('b.slug = :slug')
            ->setParameter('slug', $slug)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$breed) {
            return $this->error('Breed not found.', 404);
        }

        $data = [
            ...$this->serializeBreed($breed),
            'description'             => $breed->getDescription(),
            'temperament'             => $breed->getTemperament(),
            'available_animals_count' => $animalRepo->countPublishedByBreed($breed->getId()),
        ];

        return $this->success($data);
    }

    private function serializeBreed(Breed $b): array
    {
        return [
            'id'        => $b->getId(),
            'name'      => $b->getName(),
            'slug'      => $b->getSlug(),
            'species'   => [
                'id'   => $b->getSpecies()->getId(),
                'name' => $b->getSpecies()->getName(),
                'slug' => $b->getSpecies()->getSlug(),
            ],
            'size'      => $b->getSize(),
            'care_level'=> $b->getCareLevel(),
            'image_url' => $b->getImageUrl(),
        ];
    }
}
