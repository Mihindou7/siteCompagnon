<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SellerController extends AbstractController
{
    #[Route('/api/seller', methods: ['GET'])]
public function seller()
{
    $this->denyAccessUnlessGranted('ROLE_SELLER');

    return $this->json([
        'message' => 'Bienvenue seller'
    ]);
}
}
