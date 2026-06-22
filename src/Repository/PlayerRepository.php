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
     * Gets the current live standings applying the Top 14 rule dynamically.
     */
    public function getLeagueLeaderboard(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        // SQL using Common Table Expressions (CTE) & Window ranking functions
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
            )
            SELECT 
                p.id,
                p.name,
                SUM(rr.f1_points) as base_f1,
                SUM(rr.bonus_points) as total_bonus,
                SUM(rr.total_points) as total,
                MAX(rr.held_on) as last_active
            FROM RankedResults rr
            JOIN players p ON p.id = rr.player_id
            WHERE rr.tournament_nth <= 14
            GROUP BY p.id, p.name
            ORDER BY total DESC, name ASC
        ';

        $resultSet = $conn->executeQuery($sql);

        return $resultSet->fetchAllAssociative();
    }
}
