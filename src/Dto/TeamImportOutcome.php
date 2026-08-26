<?php

declare(strict_types=1);

namespace App\Dto;

use App\Service\TournamentImportResult;

/**
 * What a team event's import came to.
 *
 * The enum on its own is not enough here, and the counts are the reason. A
 * roster's lines and the rows it produces are not the same number: `bye` is
 * dropped, an unclaimed team scores nobody, and a blader listed in two
 * entrants is scored once. A command that recounted the file to describe what
 * happened would be reimplementing those three rules to phrase a sentence, and
 * would drift from them.
 */
final readonly class TeamImportOutcome
{
    /**
     * @param int          $teams      the entrants recorded, `bye` excluded
     * @param int          $placements the results written, one per blader scored
     * @param list<string> $unclaimed  the teams nobody was named for
     * @param list<string> $inTwoTeams bladers who appeared in more than one entrant
     */
    private function __construct(
        public TournamentImportResult $result,
        public int $teams = 0,
        public int $placements = 0,
        public array $unclaimed = [],
        public array $inTwoTeams = [],
    ) {
    }

    /**
     * @param list<string> $unclaimed
     * @param list<string> $inTwoTeams
     */
    public static function imported(
        int $teams,
        int $placements,
        array $unclaimed,
        array $inTwoTeams,
    ): self {
        return new self(TournamentImportResult::Imported, $teams, $placements, $unclaimed, $inTwoTeams);
    }

    public static function refused(TournamentImportResult $result): self
    {
        return new self($result);
    }
}
