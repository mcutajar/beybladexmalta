<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\Season;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
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
     * Drops a match the bracket no longer has.
     *
     * Explicit rather than a side effect of leaving the stage's collection,
     * because a match also leaves that collection when it *moves* to another
     * stage — and orphan removal cannot tell the two apart. `TournamentStage`
     * records why.
     */
    public function discardMatch(TournamentMatch $match): void
    {
        $match->getStage()->removeMatch($match);

        $this->getEntityManager()->remove($match);
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

    /**
     * Every archived match one blader played, newest event first.
     *
     * Keyed on the blader rather than on any single entrant, because the group
     * stage and the cut number their entrants in disjoint spaces: a blader who
     * made the cut is two `TournamentParticipant` rows for one evening, and
     * 129 of the pairs in the archive are exactly that. Joining through
     * `player` is what makes those two rows one career.
     *
     * The season is joined on the left rather than the inner, because an
     * unranked event has none: a career is match-derived and answers at any
     * scope, so joining through `t.season` would drop exactly the events #90
     * exists to make possible.
     *
     * One query rather than the two `forTournament()` needs, because nothing
     * here fetch-joins a collection — the entrants are reached through the
     * match's own two sides, which are to-one — so there is no cartesian
     * product to avoid. The games are left out on purpose: `match_games` is
     * empty for every solo bracket in the corpus, and a career log states the
     * match's scoreline rather than the sets inside it.
     *
     * @return list<TournamentMatch>
     */
    public function careerOf(Player $player): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('m', 's', 't', 'season', 'p1', 'p2', 'b1', 'b2')
            ->from(TournamentMatch::class, 'm')
            ->join('m.stage', 's')
            ->join('m.tournament', 't')
            ->leftJoin('t.season', 'season')
            ->leftJoin('m.player1', 'p1')
            ->leftJoin('m.player2', 'p2')
            ->leftJoin('p1.player', 'b1')
            ->leftJoin('p2.player', 'b2')
            ->where('b1 = :player OR b2 = :player')
            ->andWhere('m.state = :complete')
            ->setParameter('player', $player)
            ->setParameter('complete', 'complete')
            ->orderBy('t.heldOn', 'DESC')
            ->addOrderBy('t.id', 'DESC')
            ->addOrderBy('s.position', 'ASC')
            ->addOrderBy('m.consolation', 'ASC')
            ->addOrderBy('m.round', 'ASC')
            ->addOrderBy('m.challongeId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every archived match in one scope, oldest first.
     *
     * Feeds the records board, which reads the whole archive rather than one
     * blader's slice of it: about eleven hundred matches with both entrants
     * and both bladers already joined, so the board is one query rather than
     * one per match.
     *
     * The season is joined only when one is being filtered on. Leaving it out
     * of the Overall query is deliberate and outlives this ticket — an inner
     * join through `t.season` would silently drop the unranked tournaments
     * #90 makes possible from exactly the scope that is supposed to include
     * them.
     *
     * Chronological, because a win streak is a run through time and the
     * presenter extends it in place rather than sorting afterwards.
     *
     * @return list<TournamentMatch>
     */
    public function acrossTheLeague(?Season $season): array
    {
        $matches = $this->getEntityManager()->createQueryBuilder()
            ->select('m', 's', 't', 'p1', 'p2', 'b1', 'b2')
            ->from(TournamentMatch::class, 'm')
            ->join('m.stage', 's')
            ->join('m.tournament', 't')
            ->leftJoin('m.player1', 'p1')
            ->leftJoin('m.player2', 'p2')
            ->leftJoin('p1.player', 'b1')
            ->leftJoin('p2.player', 'b2')
            ->where('m.state = :complete')
            ->setParameter('complete', 'complete')
            ->orderBy('t.heldOn', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->addOrderBy('m.consolation', 'ASC')
            ->addOrderBy('m.round', 'ASC')
            ->addOrderBy('m.challongeId', 'ASC');

        if (null !== $season) {
            $matches->andWhere('t.season = :season')->setParameter('season', $season);
        }

        return $matches->getQuery()->getResult();
    }
}
