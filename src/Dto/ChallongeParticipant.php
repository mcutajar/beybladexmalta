<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One entrant, as one stage of a bracket knows them.
 *
 * Deliberately per stage rather than per tournament: Challonge gives the group
 * stage and the final stage completely disjoint id spaces — a blader who plays
 * both appears under two ids with nothing linking them but their name. Joining
 * the two is the alias table's job, not this file's.
 */
final class ChallongeParticipant
{
    public function __construct(
        public readonly int $id,
        public readonly ?int $participantId,
        public readonly ?int $seed,
        public readonly string $name,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'participant_id' => $this->participantId,
            'seed' => $this->seed,
            'name' => $this->name,
        ];
    }
}
