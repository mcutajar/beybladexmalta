<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tournament;
use App\Entity\TournamentStage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * The archive's one door.
 *
 * Participants, matches and games all cascade from the stage they belong to,
 * so there is one repository rather than four: a stage saved with its entrants
 * and matches attached is one call, and a stage removed takes them with it.
 *
 * @extends ServiceEntityRepository<TournamentStage>
 */
class TournamentStageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TournamentStage::class);
    }

    public function save(TournamentStage $stage): void
    {
        $this->getEntityManager()->persist($stage);
    }

    /**
     * Drops a stage and everything hanging off it.
     *
     * Only reached when a bracket has *lost* a stage since it was archived,
     * which is a bracket somebody edited upstream. Doctrine works out that
     * matches point at participants and deletes them in that order.
     */
    public function remove(TournamentStage $stage): void
    {
        $stage->getTournament()->removeStage($stage);

        $this->getEntityManager()->remove($stage);
    }

    /**
     * One event's archive, in the order the stages were played, with the
     * entrants, matches and games already loaded.
     *
     * Everything at once, because archiving reads all of it: a re-archive
     * looks up every match by its Challonge id, and one query per match
     * against a bracket with fifty-five of them is how a backfill of eighteen
     * brackets becomes a thousand queries.
     *
     * Two queries rather than one, and the reason is the shape of the join
     * rather than tidiness. Fetch-joining the entrants and the matches
     * together is a cartesian product — thirty entrants against sixty matches
     * is eighteen hundred rows for a stage that has ninety — so they are asked
     * for separately and Doctrine hydrates both into the same stage objects.
     *
     * @return list<TournamentStage>
     */
    public function forTournament(Tournament $tournament): array
    {
        $stages = $this->createQueryBuilder('s')
            ->addSelect('p')
            ->leftJoin('s.participants', 'p')
            ->where('s.tournament = :tournament')
            ->setParameter('tournament', $tournament)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('p.challongeId', 'ASC')
            ->getQuery()
            ->getResult();

        $this->createQueryBuilder('s')
            ->addSelect('m', 'g')
            ->leftJoin('s.matches', 'm')
            ->leftJoin('m.games', 'g')
            ->where('s.tournament = :tournament')
            ->setParameter('tournament', $tournament)
            ->orderBy('s.position', 'ASC')
            ->addOrderBy('m.round', 'ASC')
            ->addOrderBy('m.challongeId', 'ASC')
            ->addOrderBy('g.number', 'ASC')
            ->getQuery()
            ->getResult();

        return $stages;
    }
}
