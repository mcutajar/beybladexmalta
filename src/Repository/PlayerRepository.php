<?php

namespace App\Repository;

use App\Entity\Player;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Player>
 */
class PlayerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Player::class);
    }

    /**
     * Gets live standings filtering by season slug, dynamically checking matching payment conditions per season.
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
                OR rr.player_id IS NOT NULL
              )
            GROUP BY p.id, p.name
            ORDER BY total DESC, name ASC
        ';

        $resultSet = $conn->executeQuery($sql, ['seasonSlug' => $seasonSlug]);
        return $resultSet->fetchAllAssociative();
    }

    /**
     * Fetches only the top 14 contributing tournaments for a player WITHIN a specific season.
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
            'seasonSlug' => $seasonSlug
        ]);

        return $resultSet->fetchAllAssociative();
    }
}
