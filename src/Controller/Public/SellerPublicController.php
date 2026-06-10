<?php

namespace App\Controller\Public;

use App\Controller\AbstractApiController;
use App\Entity\Animal;
use App\Entity\Review;
use App\Repository\ReviewRepository;
use App\Repository\SellerRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/sellers')]
class SellerPublicController extends AbstractApiController
{
    #[Route('/{id}', methods: ['GET'])]
    public function show(
        int $id,
        SellerRepository $repo,
        ReviewRepository $reviewRepo,
    ): JsonResponse {
        $seller = $repo->find($id);

        if (!$seller || $seller->getVerifiedStatus() !== 'approved') {
            return $this->error('Seller not found.', 404);
        }

        // Rating
        $ratingResult = $reviewRepo->createQueryBuilder('r')
            ->select('AVG(r.rating) as avg_rating, COUNT(r.id) as total')
            ->where('r.seller = :seller')
            ->andWhere('r.status = :status')
            ->setParameter('seller', $seller)
            ->setParameter('status', 'published')
            ->getQuery()
            ->getSingleResult();

        // Active animals (max 6, published, newest first)
        $activeAnimals = array_filter(
            $seller->getAnimals()->toArray(),
            fn(Animal $a) => $a->getStatus() === 'published'
        );
        usort($activeAnimals, fn($a, $b) => $b->getPublishedAt() <=> $a->getPublishedAt());
        $activeAnimals = array_slice($activeAnimals, 0, 6);

        // Last 5 published reviews
        $reviews = $reviewRepo->createQueryBuilder('r')
            ->where('r.seller = :seller')
            ->andWhere('r.status = :status')
            ->setParameter('seller', $seller)
            ->setParameter('status', 'published')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(5)
            ->getQuery()
            ->getResult();

        return $this->success([
            'id'          => $seller->getId(),
            'name'        => $seller->getName(),
            'type'        => $seller->getType(),
            'description' => $seller->getDescription(),
            'logo_url'    => $seller->getLogoUrl(),
            'city'        => $seller->getCity(),
            'postal_code' => $seller->getPostalCode(),
            'rating'      => $ratingResult['avg_rating'] ? round((float) $ratingResult['avg_rating'], 1) : null,
            'reviews_count' => (int) $ratingResult['total'],
            'animals_count' => count($activeAnimals),
            'active_animals' => array_map(fn(Animal $a) => [
                'id'        => $a->getId(),
                'title'     => $a->getTitle(),
                'price'     => (float) $a->getPrice(),
                'cover_url' => $this->getCoverUrl($a),
            ], $activeAnimals),
            'reviews' => array_map(fn(Review $r) => [
                'id'               => $r->getId(),
                'rating'           => $r->getRating(),
                'comment'          => $r->getComment(),
                'buyer_first_name' => $r->getBuyer()->getFirstName(),
                'created_at'       => $r->getCreatedAt()->format(\DateTimeInterface::ATOM),
            ], $reviews),
        ]);
    }

    private function getCoverUrl(Animal $a): ?string
    {
        foreach ($a->getMedia() as $m) {
            if ($m->isCover()) return $m->getFileUrl();
        }
        return $a->getMedia()->first() ? $a->getMedia()->first()->getFileUrl() : null;
    }
}
