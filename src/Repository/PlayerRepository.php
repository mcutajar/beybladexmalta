<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Player>
 */
class PlayerRepository extends ServiceEntityRepository implements PlayerRepositoryInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    /**
     * Gets live standings filtering by season slug, dynamically checking matching payment conditions per season.
     *
     * @return list<array<string, mixed>>
     */
    public function getLeagueLeaderboard(string $seasonSlug): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
        WITH RankedResults AS (
            SELECT 
                tr.player_id,
                tr.f1_points,
                tr.bonus_points,
                tr.total_points,
                t.held_on,
                ROW_NUMBER() OVER (PARTITION BY tr.player_id ORDER BY tr.total_points DESC) as tournament_nth
            FROM tournament_results tr
            JOIN tournaments t ON t.id = tr.tournament_id
            JOIN seasons s ON s.id = t.season_id
            WHERE s.slug = :seasonSlug
        )
        SELECT 
            p.id,
            p.name,
            p.slug,
            COALESCE(SUM(rr.f1_points), 0) as base_f1,
            COALESCE(SUM(rr.bonus_points), 0) as total_bonus,
            COALESCE(SUM(rr.total_points), 0) as total,
            MAX(rr.held_on) as last_active
        FROM players p
        CROSS JOIN seasons target_s
        LEFT JOIN RankedResults rr ON p.id = rr.player_id AND rr.tournament_nth <= 14
        LEFT JOIN season_registrations sr ON sr.player_id = p.id AND sr.season_id = target_s.id
        WHERE target_s.slug = :seasonSlug
        AND (
            target_s.requires_payment = false 
            OR COALESCE(sr.paid, false) = true
        )
        GROUP BY p.id, p.name
        ORDER BY total DESC, name ASC
    ';

        $resultSet = $conn->executeQuery($sql, ['seasonSlug' => $seasonSlug]);

        return $resultSet->fetchAllAssociative();
    }

    /**
     * Fetches only the top 14 contributing tournaments for a player WITHIN a specific season.
     *
     * @return list<array<string, mixed>>
     */
    public function getPlayerContributingTournaments(int $playerId, string $seasonSlug): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            WITH RankedResults AS (
                SELECT 
                    tr.player_id,
                    tr.tournament_id,
                    tr.f1_points,
                    tr.bonus_points,
                    tr.total_points,
                    t.title as tournament_name,
                    t.held_on,
                    ROW_NUMBER() OVER (PARTITION BY tr.player_id ORDER BY tr.total_points DESC) as tournament_nth
                FROM tournament_results tr
                JOIN tournaments t ON t.id = tr.tournament_id
                JOIN seasons s ON s.id = t.season_id
                WHERE tr.player_id = :playerId AND s.slug = :seasonSlug
            )
            SELECT 
                tournament_id,
                tournament_name,
                held_on,
                f1_points,
                bonus_points,
                total_points
            FROM RankedResults
            WHERE tournament_nth <= 14
            ORDER BY held_on DESC
        ';

        $resultSet = $conn->executeQuery($sql, [
            'playerId' => $playerId,
            'seasonSlug' => $seasonSlug,
        ]);

        return $resultSet->fetchAllAssociative();
    }

    /**
     * One blader's scoring events, grouped by the season each scored in.
     *
     * The best-14 cap is applied **per season**, because that is the only
     * scope in which it means anything: it is a season's rule, and applying
     * fourteen to a whole career would be a number nobody agreed to. Which is
     * also why nothing here totals across seasons and the profile shows no
     * grand total — the contract forbids summing points across seasons, and
     * every alternative is a figure the league does not award.
     *
     * An unranked event cannot appear: it has no `TournamentResult` row, which
     * is what this reads.
     *
     * @return list<array<string, mixed>>
     */
    public function getPlayerContributionsBySeason(int $playerId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            WITH RankedResults AS (
                SELECT
                    tr.tournament_id,
                    tr.f1_points,
                    tr.bonus_points,
                    tr.total_points,
                    t.title AS tournament_name,
                    t.held_on,
                    s.id AS season_id,
                    s.slug AS season_slug,
                    s.name AS season_name,
                    ROW_NUMBER() OVER (PARTITION BY s.id ORDER BY tr.total_points DESC) AS season_nth
                FROM tournament_results tr
                JOIN tournaments t ON t.id = tr.tournament_id
                JOIN seasons s ON s.id = t.season_id
                WHERE tr.player_id = :playerId
            )
            SELECT
                tournament_id,
                tournament_name,
                held_on,
                f1_points,
                bonus_points,
                total_points,
                season_slug,
                season_name
            FROM RankedResults
            WHERE season_nth <= 14
            ORDER BY season_id DESC, held_on DESC
        ';

        return $conn->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative();
    }

    /**
     * How many bladers the archive actually reaches.
     *
     * Distinct players behind an archived entrant, rather than rows in
     * `players`: the difference is everybody the league has on record but has
     * never seen play, and the archive page is stating what it holds. It
     * counts unranked events like any other — an entrant resolves to a blader
     * whether or not the evening scored.
     */
    public function archivedBladerCount(): int
    {
        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->executeQuery(
            'SELECT COUNT(DISTINCT p.player_id) FROM tournament_participants p WHERE p.player_id IS NOT NULL',
        )->fetchOne();
    }

    public function getPlayerByName(string $name): ?Player
    {
        return $this->createQueryBuilder('p')
                ->where('LOWER(p.name) = LOWER(:name)')
                ->setParameter('name', trim($name))
                ->getQuery()
                ->getOneOrNullResult();
    }

    public function save(Player $player): void
    {
        $this->getEntityManager()->persist($player);
    }

    public function remove(Player $player): void
    {
        $this->getEntityManager()->remove($player);
    }

    /** @return list<Player> */
    public function allExcept(Player $player): array
    {
        return $this->createQueryBuilder('p')
            ->where('p != :player')
            ->setParameter('player', $player)
            ->getQuery()
            ->getResult();
    }

    public function findByName(string $name): ?Player
    {
        return $this->createQueryBuilder('p')
            ->where('LOWER(p.name) = LOWER(:name)')
            ->setParameter('name', trim($name))
            ->getQuery()
            ->getOneOrNullResult();
    }
}
