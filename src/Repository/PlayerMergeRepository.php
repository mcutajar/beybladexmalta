<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;
use App\Entity\SeasonRegistration;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentResult;
use App\Entity\TournamentTeamMember;
use Doctrine\ORM\EntityManagerInterface;

/** Queries the four histories whose ownership changes in a player merge. */
final class PlayerMergeRepository
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /** @return list<TournamentResult> */
    public function results(Player $player): array
    {
        return $this->entityManager->getRepository(TournamentResult::class)->findBy(['player' => $player]);
    }

    /** @return list<TournamentParticipant> */
    public function participants(Player $player): array
    {
        return $this->entityManager->getRepository(TournamentParticipant::class)->findBy(['player' => $player]);
    }

    /** @return list<SeasonRegistration> */
    public function registrations(Player $player): array
    {
        return $this->entityManager->getRepository(SeasonRegistration::class)->findBy(['player' => $player]);
    }

    /** @return list<TournamentTeamMember> */
    public function teamMemberships(Player $player): array
    {
        return $this->entityManager->getRepository(TournamentTeamMember::class)->findBy(['player' => $player]);
    }
}
