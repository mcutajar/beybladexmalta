<?php

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

    /**
     * Fetches all player results for a single tournament.
     */
    public function getTournamentStandings(int $tournamentId): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT 
                p.id as player_id,
                p.name as player_name,
                tr.f1_points,
                tr.bonus_points,
                tr.total_points
            FROM tournament_results tr
            JOIN players p ON p.id = tr.player_id
            WHERE tr.tournament_id = :tournamentId
            ORDER BY tr.total_points DESC, p.name ASC
        ';

        $resultSet = $conn->executeQuery($sql, ['tournamentId' => $tournamentId]);

        return $resultSet->fetchAllAssociative();
    }
}
