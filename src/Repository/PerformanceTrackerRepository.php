<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PerformanceTracker;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PerformanceTracker> */
final class PerformanceTrackerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PerformanceTracker::class);
    }

    public function save(PerformanceTracker $tracker): void
    {
        $this->getEntityManager()->persist($tracker);
    }

    public function findByEditToken(string $token): ?PerformanceTracker
    {
        return $this->findOneBy(['editTokenHash' => hash('sha256', $token)]);
    }

    public function findByShareId(string $shareId): ?PerformanceTracker
    {
        return $this->findOneBy(['shareId' => $shareId]);
    }
}
