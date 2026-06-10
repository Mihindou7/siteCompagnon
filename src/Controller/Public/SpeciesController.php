<?php

namespace App\Controller\Public;

use App\Controller\AbstractApiController;
use App\Entity\Species;
use App\Repository\AnimalRepository;
use App\Repository\SpeciesRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/species')]
class SpeciesController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function index(
        SpeciesRepository $repo,
        AnimalRepository $animalRepo,
    ): JsonResponse {
        $species = $repo->createQueryBuilder('s')
            ->leftJoin('s.breeds', 'b')
            ->addSelect('b')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();

        $data = array_map(fn(Species $s) => [
            ...$this->serializeSpecies($s),
            'breeds_count'            => $s->getBreeds()->count(),
            'available_animals_count' => $animalRepo->countPublishedBySpecies($s->getId()),
        ], $species);

        return $this->success($data);
    }

    #[Route('/{slug}', methods: ['GET'])]
    public function show(
        string $slug,
        SpeciesRepository $repo,
        AnimalRepository $animalRepo,
    ): JsonResponse {
        $species = $repo->findOneBy(['slug' => $slug]);
        if (!$species) {
            return $this->error('Species not found.', 404);
        }

        $data = $this->serializeSpecies($species);

        $data['breeds'] = array_map(fn($breed) => [
            'id'                      => $breed->getId(),
            'name'                    => $breed->getName(),
            'slug'                    => $breed->getSlug(),
            'size'                    => $breed->getSize(),
            'care_level'              => $breed->getCareLevel(),
            'image_url'               => $breed->getImageUrl(),
            'available_animals_count' => $animalRepo->countPublishedByBreed($breed->getId()),
        ], $species->getBreeds()->toArray());

        return $this->success($data);
    }

    private function serializeSpecies(Species $s): array
    {
        return [
            'id'                  => $s->getId(),
            'name'                => $s->getName(),
            'slug'                => $s->getSlug(),
            'family'              => $s->getFamily(),
            'description'         => $s->getDescription(),
            'temperament'         => $s->getTemperament(),
            'life_expectancy_min' => $s->getLifeExpectancyMin(),
            'life_expectancy_max' => $s->getLifeExpectancyMax(),
            'diet_type'           => $s->getDietType(),
            'avg_monthly_cost'    => $s->getAvgMonthlyCost() ? (float) $s->getAvgMonthlyCost() : null,
            'care_level'          => $s->getCareLevel(),
            'image_url'           => $s->getImageUrl(),
        ];
    }
}
