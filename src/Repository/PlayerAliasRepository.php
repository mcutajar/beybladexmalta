<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\PlayerAlias;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PlayerAlias>
 */
class PlayerAliasRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PlayerAlias::class);
    }

    public function findByNormalised(string $normalised): ?PlayerAlias
    {
        return $this->findOneBy(['normalised' => $normalised]);
    }

    /**
     * Every alias there is, with the blader each points at already loaded.
     *
     * Resolving one name means comparing it against all of them, so the whole
     * table is read at once rather than a row at a time. It holds a few
     * hundred rows against seventy-six bladers and is read by a console
     * command; the join is here so that listing them is one query rather than
     * one per row.
     *
     * @return list<PlayerAlias>
     */
    public function all(): array
    {
        return $this->ordered()->getQuery()->getResult();
    }

    /**
     * @return list<PlayerAlias>
     */
    public function forPlayer(Player $player): array
    {
        return $this->ordered()
            ->andWhere('a.player = :player')
            ->setParameter('player', $player)
            ->getQuery()
            ->getResult();
    }

    public function save(PlayerAlias $alias): void
    {
        $this->getEntityManager()->persist($alias);
    }

    public function remove(PlayerAlias $alias): void
    {
        $this->getEntityManager()->remove($alias);
    }

    private function ordered(): QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->addSelect('p')
            ->join('a.player', 'p')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('a.alias', 'ASC');
    }
}
