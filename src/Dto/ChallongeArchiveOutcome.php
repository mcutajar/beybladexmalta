<?php

declare(strict_types=1);

namespace App\Dto;

use App\Service\ChallongeArchiveResult;

/**
 * What archiving a bracket wrote, and what it could not read.
 *
 * The counts are here rather than recounted by a caller for the same reason
 * `TeamImportOutcome`'s are: the rules that make a snapshot's contents and the
 * rows written differ — a game that is not written because it is the only one,
 * a stage dropped because the bracket lost it — live in one place, and a
 * command that recounted the file to phrase a sentence would drift from them.
 *
 * `unrecognised` is the one worth reading. Those are the display names that
 * reached no blader, so the entrants they belong to are archived with their
 * matches and attached to nobody: they are the head-to-heads that will be
 * missing from a profile until somebody files an alias. Nothing is lost by
 * waiting — re-archiving picks them up — but nothing picks them up on its own
 * either.
 */
final readonly class ChallongeArchiveOutcome
{
    /**
     * @param int          $bladers      participant rows that reached a blader
     * @param list<string> $unrecognised the spellings that reached nobody, once each
     * @param int          $discarded    rows dropped because the bracket no longer has them
     */
    private function __construct(
        public ChallongeArchiveResult $result,
        public int $stages = 0,
        public int $participants = 0,
        public int $matches = 0,
        public int $games = 0,
        public int $bladers = 0,
        public array $unrecognised = [],
        public int $discarded = 0,
    ) {
    }

    /**
     * @param list<string> $unrecognised
     */
    public static function archived(
        int $stages,
        int $participants,
        int $matches,
        int $games,
        int $bladers,
        array $unrecognised,
        int $discarded,
    ): self {
        return new self(
            ChallongeArchiveResult::Archived,
            $stages,
            $participants,
            $matches,
            $games,
            $bladers,
            $unrecognised,
            $discarded,
        );
    }

    public static function refused(ChallongeArchiveResult $result): self
    {
        return new self($result);
    }

    public function wasArchived(): bool
    {
        return ChallongeArchiveResult::Archived === $this->result;
    }
}
