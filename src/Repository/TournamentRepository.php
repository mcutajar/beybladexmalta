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
