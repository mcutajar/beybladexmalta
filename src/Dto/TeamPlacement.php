<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One line of a team event's roster: where an entrant finished, what the
 * bracket called it, and who was in it.
 *
 * The rank is carried rather than inferred from the entry's position, because
 * an entrant can be dropped without the ones below it moving up. `bye` is an
 * entrant of `uhxii7az` at rank 12 and is not a team; taking it out must not
 * renumber anything, so the ranks stay Challonge's.
 *
 * No members is unclaimed rather than incomplete.
 */
final readonly class TeamPlacement
{
    /**
     * @param list<string> $memberNames the bladers in it, in no meaningful order
     */
    public function __construct(
        public int $rank,
        public string $teamName,
        public array $memberNames = [],
    ) {
    }

    public function isUnclaimed(): bool
    {
        return [] === $this->memberNames;
    }
}
