<?php

namespace App\Repository;

use App\Service\FlusherInterface;
use Doctrine\ORM\EntityManagerInterface;

final class DoctrineFlusher implements FlusherInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
