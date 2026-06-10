<?php

namespace App\Controller\User;

use App\Controller\AbstractApiController;
use App\DTO\Seller\SellerApplyDTO;
use App\DTO\Seller\SellerUpdateDTO;
use App\Entity\Seller;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/me/seller')]
#[IsGranted('ROLE_USER')]
class SellerUserController extends AbstractApiController
{
    #[Route('', methods: ['GET'])]
    public function get(#[CurrentUser] User $user): JsonResponse
    {
        $seller = $user->getSeller();
        if ($seller === null) {
            return $this->success(null);
        }

        return $this->success($this->serializeSeller($seller));
    }

    #[Route('/apply', methods: ['POST'])]
    public function apply(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SellerApplyDTO $dto,
        EntityManagerInterface $em,
    ): JsonResponse {
        if (!$user->isEmailVerified()) {
            return $this->error('Email verification required.', 403);
        }

        $existing = $user->getSeller();
        if ($existing !== null && in_array($existing->getVerifiedStatus(), ['pending', 'approved'], true)) {
            return $this->error('A seller application is already pending or approved.', 409);
        }

        $seller = new Seller();
        $seller->setUser($user);
        $seller->setName($dto->name);
        $seller->setType($dto->type);
        $seller->setSiret($dto->siret);
        $seller->setCity($dto->city);
        $seller->setPostalCode($dto->postalCode);
        $seller->setAddress($dto->address);
        $seller->setDescription($dto->description);
        $seller->setVerifiedStatus('pending');

        $em->persist($seller);
        $em->flush();

        return $this->created([
            'id'              => $seller->getId(),
            'verified_status' => $seller->getVerifiedStatus(),
        ]);
    }

    #[Route('', methods: ['PATCH'])]
    public function update(
        #[CurrentUser] User $user,
        #[MapRequestPayload] SellerUpdateDTO $dto,
        EntityManagerInterface $em,
    ): JsonResponse {
        $seller = $user->getSeller();
        if ($seller === null) {
            return $this->error('No seller profile found.', 404);
        }

        if ($dto->name !== null) $seller->setName($dto->name);
        if ($dto->type !== null) $seller->setType($dto->type);
        if ($dto->siret !== null) $seller->setSiret($dto->siret);
        if ($dto->address !== null) $seller->setAddress($dto->address);
        if ($dto->city !== null) $seller->setCity($dto->city);
        if ($dto->postalCode !== null) $seller->setPostalCode($dto->postalCode);
        if ($dto->description !== null) $seller->setDescription($dto->description);
        if ($dto->logoUrl !== null) $seller->setLogoUrl($dto->logoUrl);

        // Auto re-submit if rejected
        if ($seller->getVerifiedStatus() === 'rejected') {
            $seller->setVerifiedStatus('pending');
            $seller->setRejectionReason(null);
        }

        $seller->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->success($this->serializeSeller($seller));
    }

    private function serializeSeller(Seller $seller): array
    {
        return [
            'id'               => $seller->getId(),
            'name'             => $seller->getName(),
            'type'             => $seller->getType(),
            'siret'            => $seller->getSiret(),
            'description'      => $seller->getDescription(),
            'verified_status'  => $seller->getVerifiedStatus(),
            'rejection_reason' => $seller->getRejectionReason(),
            'city'             => $seller->getCity(),
            'postal_code'      => $seller->getPostalCode(),
            'created_at'       => $seller->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
