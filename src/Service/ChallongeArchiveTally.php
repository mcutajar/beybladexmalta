<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeArchiveOutcome;

/**
 * The running count an archive keeps while it works, and the outcome it
 * becomes at the end.
 *
 * Mutable on purpose and internal to `ChallongeArchiveService`: the alternative
 * is six accumulators threaded through four private methods by reference,
 * which is how one of them ends up counting something twice. `outcome()` is
 * where it turns back into the readonly thing callers see.
 */
final class ChallongeArchiveTally
{
    public int $stages = 0;

    public int $participants = 0;

    public int $matches = 0;

    public int $games = 0;

    public int $bladers = 0;

    public int $discarded = 0;

    /**
     * Kept as a set, because a spelling nobody recognises is usually the same
     * spelling in both stages of the bracket.
     *
     * @var array<string, true>
     */
    private array $unrecognised = [];

    public function nobodyIsCalled(string $name): void
    {
        $this->unrecognised[$name] = true;
    }

    /**
     * @return list<string>
     */
    public function unrecognised(): array
    {
        $names = array_keys($this->unrecognised);

        sort($names);

        return $names;
    }

    public function outcome(): ChallongeArchiveOutcome
    {
        return ChallongeArchiveOutcome::archived(
            stages: $this->stages,
            participants: $this->participants,
            matches: $this->matches,
            games: $this->games,
            bladers: $this->bladers,
            unrecognised: $this->unrecognised(),
            discarded: $this->discarded,
        );
    }
}
