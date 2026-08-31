<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentResult;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TournamentResult>
 */
class TournamentResultRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentResult::class);
    }

    public function save(TournamentResult $result): void
    {
        $this->getEntityManager()->persist($result);
    }

    /**
     * Where a blader finished at every event they scored at, newest first.
     *
     * Across every season, because a career is not season-scoped: 35 bladers
     * have played in both. The page says which season each event belonged to
     * rather than pretending the archive stops at the one in the URL.
     *
     * @return list<TournamentResult>
     */
    public function careerOf(Player $player): array
    {
        return $this->createQueryBuilder('r')
            ->addSelect('t', 'season')
            ->join('r.tournament', 't')
            ->join('t.season', 'season')
            ->where('r.player = :player')
            ->setParameter('player', $player)
            ->orderBy('t.heldOn', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Whether a blader already finished somewhere in this event.
     *
     * Asked before a team claim awards a placement, because a blader who is
     * already on the board played for another entrant and a second placement
     * would score them twice for one evening.
     */
    public function existsFor(Tournament $tournament, Player $player): bool
    {
        return null !== $this->findOneBy([
            'tournament' => $tournament,
            'player' => $player,
        ]);
    }
}
