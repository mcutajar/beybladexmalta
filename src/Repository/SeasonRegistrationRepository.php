<?php

namespace App\Repository;

use App\Entity\SeasonRegistration;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SeasonRegistration>
 */
class SeasonRegistrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SeasonRegistration::class);
    }

    /**
     * Fetches all registered seasonal payments ordered by season and player name.
     */
    public function getAllSeasonalPayments(): array
    {
        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT 
                s.name as season_name,
                s.slug as season_slug,
                p.name as player_name,
                p.id as player_id,
                sr.paid
            FROM season_registrations sr
            JOIN players p ON p.id = sr.player_id
            JOIN seasons s ON s.id = sr.season_id
            WHERE sr.paid = true
            ORDER BY s.id DESC, p.name ASC
        ';

        return $conn->executeQuery($sql)->fetchAllAssociative();
    }
}
