<?php

namespace App\Repository;

use App\Entity\Animal;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

class AnimalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Animal::class);
    }

    public function findPublicQueryBuilder(array $filters = []): QueryBuilder
    {
        $now = new \DateTimeImmutable();

        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.species', 's')
            ->leftJoin('a.breed', 'b')
            ->leftJoin('a.seller', 'seller')
            ->leftJoin('a.media', 'm', 'WITH', 'm.isCover = true')
            ->addSelect('s', 'b', 'seller', 'm')
            ->where('a.status = :status')
            ->setParameter('status', 'published');

        if (!empty($filters['species_id'])) {
            $qb->andWhere('s.id = :speciesId')->setParameter('speciesId', (int) $filters['species_id']);
        }

        if (!empty($filters['species_slug'])) {
            $qb->andWhere('s.slug = :speciesSlug')->setParameter('speciesSlug', $filters['species_slug']);
        }

        if (!empty($filters['breed_id'])) {
            $qb->andWhere('b.id = :breedId')->setParameter('breedId', (int) $filters['breed_id']);
        }

        if (!empty($filters['breed_slug'])) {
            $qb->andWhere('b.slug = :breedSlug')->setParameter('breedSlug', $filters['breed_slug']);
        }

        if (!empty($filters['sex'])) {
            $qb->andWhere('a.sex = :sex')->setParameter('sex', $filters['sex']);
        }

        if (!empty($filters['city'])) {
            $qb->andWhere('a.city LIKE :city')->setParameter('city', '%' . $filters['city'] . '%');
        }

        if (!empty($filters['postal_code'])) {
            $qb->andWhere('a.postalCode = :postalCode')->setParameter('postalCode', $filters['postal_code']);
        }

        if (isset($filters['price_min']) && $filters['price_min'] !== '') {
            $qb->andWhere('a.price >= :priceMin')->setParameter('priceMin', (float) $filters['price_min']);
        }

        if (isset($filters['price_max']) && $filters['price_max'] !== '') {
            $qb->andWhere('a.price <= :priceMax')->setParameter('priceMax', (float) $filters['price_max']);
        }

        if (!empty($filters['seller_type'])) {
            $qb->andWhere('seller.type = :sellerType')->setParameter('sellerType', $filters['seller_type']);
        }

        // age_min in months → birthdate <= NOW - age_min months
        if (isset($filters['age_min']) && $filters['age_min'] !== '') {
            $maxBirthdate = $now->modify('-' . (int) $filters['age_min'] . ' months');
            $qb->andWhere('a.birthdate <= :maxBirthdate')->setParameter('maxBirthdate', $maxBirthdate);
        }

        // age_max in months → birthdate >= NOW - age_max months
        if (isset($filters['age_max']) && $filters['age_max'] !== '') {
            $minBirthdate = $now->modify('-' . (int) $filters['age_max'] . ' months');
            $qb->andWhere('a.birthdate >= :minBirthdate')->setParameter('minBirthdate', $minBirthdate);
        }

        // Sort
        $sort = $filters['sort'] ?? 'published_at_desc';
        match ($sort) {
            'price_asc'        => $qb->orderBy('a.price', 'ASC'),
            'price_desc'       => $qb->orderBy('a.price', 'DESC'),
            'age_asc'          => $qb->orderBy('a.birthdate', 'DESC'), // younger = birthdate closer to now
            default            => $qb->orderBy('a.publishedAt', 'DESC'),
        };

        return $qb;
    }

    public function findSimilar(Animal $animal, int $limit = 3): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.media', 'm', 'WITH', 'm.isCover = true')
            ->addSelect('m')
            ->where('a.status = :status')
            ->andWhere('a.id != :currentId')
            ->setParameter('status', 'published')
            ->setParameter('currentId', $animal->getId())
            ->setMaxResults($limit);

        // Prefer same breed
        if ($animal->getBreed()) {
            $qb->andWhere('a.breed = :breed')->setParameter('breed', $animal->getBreed());
            $results = $qb->getQuery()->getResult();
            if (count($results) >= $limit) {
                return $results;
            }
        }

        // Fallback: same species
        $qb2 = $this->createQueryBuilder('a')
            ->leftJoin('a.media', 'm', 'WITH', 'm.isCover = true')
            ->addSelect('m')
            ->where('a.status = :status')
            ->andWhere('a.id != :currentId')
            ->andWhere('a.species = :species')
            ->setParameter('status', 'published')
            ->setParameter('currentId', $animal->getId())
            ->setParameter('species', $animal->getSpecies())
            ->setMaxResults($limit);

        return $qb2->getQuery()->getResult();
    }

    public function countPublishedBySpecies(int $speciesId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.species = :s')->andWhere('a.status = :st')
            ->setParameter('s', $speciesId)->setParameter('st', 'published')
            ->getQuery()->getSingleScalarResult();
    }

    public function countPublishedByBreed(int $breedId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.breed = :b')->andWhere('a.status = :st')
            ->setParameter('b', $breedId)->setParameter('st', 'published')
            ->getQuery()->getSingleScalarResult();
    }
}
