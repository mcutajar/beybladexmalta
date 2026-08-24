<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One standings row with the entrant it belongs to.
 *
 * The snapshot deliberately keeps the two apart — a standings row is a
 * transcription of a table, and the table does not reliably say who it is
 * about. This is the pair after the join, which is the first point at which
 * "rank 3 was Obelix" can be said at all.
 */
final class ChallongePlacing
{
    public function __construct(
        public readonly ChallongeStanding $standing,
        public readonly ?ChallongeParticipant $participant,
        public readonly ChallongeJoin $join,
    ) {
    }

    public function rank(): int
    {
        return $this->standing->rank;
    }

    /**
     * The entrant's name as the bracket recorded it, which is what the alias
     * table resolves to a blader. Falls back to what the row itself printed
     * when there is no entrant to point at.
     */
    public function name(): ?string
    {
        return $this->participant->name
            ?? $this->standing->name
            ?? $this->standing->challongeUser;
    }

    public function isResolved(): bool
    {
        return null !== $this->participant;
    }
}
