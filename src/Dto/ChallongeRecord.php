<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The statistics a standings row printed about one entrant, typed.
 *
 * A snapshot keeps a standings table column for column, under the header
 * labels Challonge wrote above them, precisely so that nothing is renamed into
 * our vocabulary in a file that cannot change. This is that reading, made when
 * the file is read rather than when it is written — where a mistake costs a
 * re-parse rather than a re-fetch of a bracket that may be gone.
 *
 * Every field is nullable and every one of them is absent somewhere in the
 * corpus. A Swiss table carries Buchholz, TB and Pts Diff; a round robin
 * carries Set Wins instead; the `Byes` column exists only in the eleven tables
 * that had any; and the standings of a cut carry no columns at all — eight
 * rows of nothing but a rank and a match history. A zero would be a claim the
 * bracket did not make.
 */
final readonly class ChallongeRecord
{
    /**
     * @param ?int   $wins               match wins, from the `Match W-L-T` column
     * @param ?int   $byes               a round nobody was paired against, worth a win
     * @param ?float $score              match points: a win is 1.0 and a tie 0.5, so it is not an integer
     * @param ?float $buchholz           Challonge's `Buchholz` column, which is the *median* Buchholz:
     *                                   the opponents' scores with the best and the worst dropped.
     *                                   Measured against `nppk0890`, twelve of twelve rows match that
     *                                   and none match the plain sum — Guzman's opponents scored
     *                                   2, 3, 3, 4 and 4 for a stated 10.0, not 16.0.
     * @param ?float $tieBreak           Challonge's `TB` column, whatever it was configured to hold
     * @param ?int   $points             total points scored, from Challonge's `Pts` tiebreaker
     * @param ?int   $pointsDifferential Beyblade points for less against, as `Pts Diff`
     */
    public function __construct(
        public ?int $wins = null,
        public ?int $losses = null,
        public ?int $ties = null,
        public ?int $byes = null,
        public ?float $score = null,
        public ?float $buchholz = null,
        public ?float $tieBreak = null,
        public ?int $points = null,
        public ?int $pointsDifferential = null,
    ) {
    }

    /**
     * A row that said nothing measurable — the standings of a cut.
     */
    public static function nothing(): self
    {
        return new self();
    }
}
