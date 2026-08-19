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
     * Fetches all player results for a single tournament.
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
