<?php

namespace App\EventListener;

use App\Entity\User;
use Lexik\Bundle\JWTAuthenticationBundle\Event\JWTCreatedEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: 'lexik_jwt_authentication.on_jwt_created')]
class JWTCreatedListener
{
    public function __invoke(JWTCreatedEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $data = $event->getData();
        $data['id'] = $user->getId();
        $data['status'] = $user->getStatus();

        $seller = $user->getSeller();
        if ($seller !== null) {
            $data['seller_id'] = $seller->getId();
            $data['seller_status'] = $seller->getVerifiedStatus();
        }

        $event->setData($data);
    }
}
