<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One row of a standings table, transcribed rather than interpreted.
 *
 * The spine — rank, who the row is about, and which matches it links to — is
 * the same in every bracket, so it is parsed. The statistics are not: a Swiss
 * table carries Buchholz and Pts Diff, a round robin carries Set Wins, and the
 * `Byes` column exists only in the brackets that had byes. Those stay under
 * the header label Challonge printed above them, so no column can be silently
 * dropped or read as the wrong one.
 */
final class ChallongeStanding
{
    /**
     * @param list<string>          $labels   badges Challonge puts on the row, e.g. "Advanced"
     * @param list<int>             $matchIds the matches in the row's match-history cell, which is how a
     *                                        row is joined to a participant — the name in the cell is not
     *                                        always the participant's
     * @param array<string, string> $columns  every other cell, keyed by its header label
     */
    public function __construct(
        public readonly int $rank,
        public readonly ?string $name,
        public readonly ?string $challongeUser,
        public readonly array $labels,
        public readonly array $matchIds,
        public readonly array $columns,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'rank' => $this->rank,
            'name' => $this->name,
            'challonge_user' => $this->challongeUser,
            'labels' => $this->labels,
            'match_ids' => $this->matchIds,
            'columns' => $this->columns,
        ];
    }
}
