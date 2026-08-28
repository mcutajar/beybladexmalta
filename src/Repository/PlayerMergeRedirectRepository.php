<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\PlayerMergeRedirect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlayerMergeRedirect> */
final class PlayerMergeRedirectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerMergeRedirect::class);
    }

    public function save(PlayerMergeRedirect $redirect): void
    {
        $this->getEntityManager()->persist($redirect);
    }

    public function survivorFor(int $oldPlayerId): ?Player
    {
        return $this->find($oldPlayerId)?->getSurvivor();
    }

    /** @return list<PlayerMergeRedirect> */
    public function pointingTo(Player $survivor): array
    {
        return $this->findBy(['survivor' => $survivor]);
    }
}
