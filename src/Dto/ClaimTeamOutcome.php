<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\TournamentTeam;
use App\Service\ClaimTeamResult;

/**
 * What happened when somebody said who was in a team.
 *
 * The enum is what a caller matches on; the two extra fields are what a
 * message needs. `blader` is the name that stopped the claim, because a claim
 * names two or three people and "no such blader" is useless without saying
 * which. `team` is the row that was found, so the command can say where it
 * finished without looking it up again.
 */
final readonly class ClaimTeamOutcome
{
    /**
     * @param list<string> $attached the bladers now in the team, under the
     *                               names the database holds
     */
    private function __construct(
        public ClaimTeamResult $result,
        public ?TournamentTeam $team = null,
        public ?string $blader = null,
        public array $attached = [],
    ) {
    }

    /**
     * @param list<string> $attached
     */
    public static function claimed(TournamentTeam $team, array $attached): self
    {
        return new self(ClaimTeamResult::Claimed, $team, attached: $attached);
    }

    public static function alreadyRecorded(TournamentTeam $team): self
    {
        return new self(ClaimTeamResult::AlreadyRecorded, $team);
    }

    /**
     * Every outcome that is not one of the two above.
     *
     * The enum carries the successes too, so this guards rather than trusts:
     * a `Claimed` that came through here would report a claim with nobody
     * attached, and nothing downstream would notice.
     */
    public static function refused(ClaimTeamResult $result, ?TournamentTeam $team = null, ?string $blader = null): self
    {
        if (in_array($result, [ClaimTeamResult::Claimed, ClaimTeamResult::AlreadyRecorded], true)) {
            throw new \LogicException(sprintf('%s is not a refusal.', $result->name));
        }

        return new self($result, $team, $blader);
    }
}
