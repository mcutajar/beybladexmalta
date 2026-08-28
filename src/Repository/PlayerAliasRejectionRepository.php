<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\PlayerAliasRejection;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PlayerAliasRejection> */
class PlayerAliasRejectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerAliasRejection::class);
    }

    public function findPair(Player $player, string $normalised): ?PlayerAliasRejection
    {
        return $this->findOneBy(['player' => $player, 'normalised' => $normalised]);
    }

    /** @return list<PlayerAliasRejection> */
    public function all(): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('p')
            ->join('r.player', 'p')
            ->orderBy('r.normalised', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(PlayerAliasRejection $rejection): void
    {
        $this->getEntityManager()->persist($rejection);
    }

    public function remove(PlayerAliasRejection $rejection): void
    {
        $this->getEntityManager()->remove($rejection);
    }
}
