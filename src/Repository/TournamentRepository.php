<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tournament;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Tournament>
 */
class TournamentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Tournament::class);
    }

    public function save(Tournament $tournament): void
    {
        $this->getEntityManager()->persist($tournament);
    }

    /**
     * Every event the league has held, oldest first, with its finishing order
     * already loaded.
     *
     * One query rather than one per tournament, because the bootstrap pass in
     * #51 walks all of them and reads every result of each. The results come
     * back ordered by rank, which is what makes "rank n of the bracket is line
     * n of the import" a comparison rather than a sort.
     *
     * @return list<Tournament>
     */
    public function everyEventInOrder(): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('r', 'p')
            ->leftJoin('t.results', 'r')
            ->leftJoin('r.player', 'p')
            ->orderBy('t.heldOn', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->addOrderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * The events with this exact title.
     *
     * A list rather than one, because nothing stops two events being called
     * the same thing and the title is how the ledger names one. A caller that
     * gets two back refuses rather than picks.
     *
     * @return list<Tournament>
     */
    public function findByTitle(string $title): array
    {
        return $this->findBy(['title' => trim($title)], ['heldOn' => 'ASC', 'id' => 'ASC']);
    }

    /**
     * Every event that records which bracket it came from.
     *
     * A list rather than a lookup by slug, because the same bracket is named
     * three different ways across `repeat.sh` — `challonge.com/<slug>`, a
     * subdomain, and the `/vi/<slug>` invite links — and normalising a URL to
     * its slug is `ChallongeUrl`'s job rather than SQL's. There are twenty
     * rows.
     *
     * @return list<Tournament>
     */
    public function everyEventWithABracket(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.challongeUrl IS NOT NULL')
            ->andWhere("t.challongeUrl != ''")
            ->orderBy('t.heldOn', 'ASC')
            ->addOrderBy('t.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Every archived event with the two figures the archive page states about
     * it, oldest first within each season.
     *
     * One query rather than one per event, and raw SQL rather than DQL because
     * both figures are counts over collections: fetch-joining entrants and
     * matches together is a cartesian product — thirty-one entrants against
     * eighty-seven matches is the largest event in the corpus — and two
     * `COUNT(DISTINCT)` subqueries are cheaper than either.
     *
     * The season is joined on the left. An inner join would drop exactly the
     * unranked events this page exists to make findable.
     *
     * @return list<array<string, mixed>>
     */
    public function archiveIndex(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT
                t.id,
                t.title,
                t.held_on,
                t.challonge_url,
                s.slug AS season_slug,
                s.name AS season_name,
                (
                    SELECT COUNT(*)
                    FROM tournament_participants p
                    JOIN tournament_stages st ON st.id = p.stage_id
                    WHERE st.tournament_id = t.id AND st.position = 0
                ) AS entrants,
                (
                    SELECT COUNT(*)
                    FROM tournament_matches m
                    WHERE m.tournament_id = t.id AND m.state = \'complete\'
                ) AS matches,
                (
                    SELECT COUNT(*)
                    FROM tournament_teams tt
                    WHERE tt.tournament_id = t.id
                ) AS teams,
                (
                    SELECT pl.name
                    FROM tournament_results tr
                    JOIN players pl ON pl.id = tr.player_id
                    WHERE tr.tournament_id = t.id AND tr.bonus_points > 0
                    ORDER BY tr.rank ASC
                    LIMIT 1
                ) AS knockout_winner
            FROM tournaments t
            LEFT JOIN seasons s ON s.id = t.season_id
            ORDER BY s.id ASC NULLS LAST, t.held_on ASC, t.id ASC
        ';

        return $conn->executeQuery($sql)->fetchAllAssociative();
    }

    /**
     * Fetches all player results for a single tournament.
     *
     * The rank is selected rather than counted off the rows, because a team
     * event awards one to every blader in the entrant: two people share tenth
     * place, and a row's position in the list stops being its finish.
     *
     * @return list<array<string, mixed>>
     */
    public function getTournamentStandings(int $tournamentId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT 
                p.id as player_id,
                p.name as player_name,
                tr.rank,
                tr.f1_points,
                tr.bonus_points,
                tr.total_points
            FROM tournament_results tr
            JOIN players p ON p.id = tr.player_id
            WHERE tr.tournament_id = :tournamentId
            ORDER BY tr.rank ASC, p.name ASC
        ';

        $resultSet = $conn->executeQuery($sql, ['tournamentId' => $tournamentId]);

        return $resultSet->fetchAllAssociative();
    }
}
