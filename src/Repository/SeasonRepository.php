<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Season;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Season>
 */
class SeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Season::class);
    }

    public function findBySlug(string $slug): ?Season
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Every season, oldest first.
     *
     * The order is the id, because a season has no date of its own: it is
     * created before its first event and the tournaments carry the dates. The
     * scope selector lists them in this order and the last one is the current
     * season.
     *
     * @return list<Season>
     */
    public function ordered(): array
    {
        return $this->findBy([], ['id' => 'ASC']);
    }

    public function save(Season $season): void
    {
        $this->getEntityManager()->persist($season);
    }
}
