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
