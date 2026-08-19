<?php

declare(strict_types=1);

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

    public function flushThen(callable $afterFlush): void
    {
        $this->entityManager->beginTransaction();

        try {
            $this->entityManager->flush();

            $afterFlush();

            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            /*
             * A failed flush may already have unwound the transaction on its
             * own, so only roll back what is still open.
             */
            if ($this->entityManager->getConnection()->isTransactionActive()) {
                $this->entityManager->rollback();
            }

            throw $exception;
        }
    }
}
