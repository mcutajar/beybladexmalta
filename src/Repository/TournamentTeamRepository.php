<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tournament;
use App\Entity\TournamentTeam;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TournamentTeam>
 */
class TournamentTeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentTeam::class);
    }

    /**
     * Members cascade, so a team saved with its roster attached needs one
     * call rather than one per blader.
     */
    public function save(TournamentTeam $team): void
    {
        $this->getEntityManager()->persist($team);
    }

    /**
     * One event's entrants in finishing order, rosters already loaded.
     *
     * @return list<TournamentTeam>
     */
    public function forTournament(Tournament $tournament): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('m', 'p')
            ->leftJoin('t.members', 'm')
            ->leftJoin('m.player', 'p')
            ->where('t.tournament = :tournament')
            ->setParameter('tournament', $tournament)
            ->orderBy('t.rank', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every team the league has on record, event by event, oldest first.
     *
     * One query rather than one per event: there are two team events today and
     * `app:team list` reads all of them at once.
     *
     * @return list<TournamentTeam>
     */
    public function everyTeam(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('e', 'm', 'p')
            ->join('t.tournament', 'e')
            ->leftJoin('t.members', 'm')
            ->leftJoin('m.player', 'p')
            ->orderBy('e.heldOn', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->addOrderBy('t.rank', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The entrant of this event that a spelling reaches, looked up by the
     * folded form so `legion` finds the row the bracket wrote as `legion ()`.
     */
    public function findByNormalised(Tournament $tournament, string $normalised): ?TournamentTeam
    {
        return $this->findOneBy([
            'tournament' => $tournament,
            'normalised' => $normalised,
        ]);
    }
}
