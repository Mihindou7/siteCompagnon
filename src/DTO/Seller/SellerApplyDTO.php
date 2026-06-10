<?php

namespace App\DTO\Seller;

use Symfony\Component\Validator\Constraints as Assert;

class SellerApplyDTO
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ['breeder', 'pet_shop'])]
    public string $type = '';

    #[Assert\Length(min: 14, max: 14, exactMessage: 'SIRET must be 14 digits.')]
    #[Assert\Regex(pattern: '/^\d{14}$/', message: 'SIRET must contain 14 digits.')]
    public ?string $siret = null;

    #[Assert\NotBlank]
    public string $city = '';

    #[Assert\NotBlank]
    public string $postalCode = '';

    public ?string $address = null;
    public ?string $description = null;
}
