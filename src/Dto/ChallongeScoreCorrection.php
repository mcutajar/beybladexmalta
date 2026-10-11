<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One fact the league knows is wrong in a bracket it cannot edit.
 *
 * The captured snapshot remains Challonge's verbatim account. This is the
 * separate, reviewable interpretation applied while previewing and archiving
 * it, with both the value Challonge stated and the value the organiser
 * confirmed so that an unrelated upstream change cannot be overwritten.
 */
final class ChallongeScoreCorrection
{
    /**
     * @param list<list<int>>                                                     $fromGames
     * @param list<int>                                                           $fromScore
     * @param list<list<int>>                                                     $toGames
     * @param list<int>                                                           $toScore
     * @param list<array{name: string, column: string, from: string, to: string}> $standings
     */
    public function __construct(
        public readonly int $stage,
        public readonly int $matchId,
        public readonly array $fromGames,
        public readonly array $fromScore,
        public readonly array $toGames,
        public readonly array $toScore,
        public readonly array $standings,
        public readonly string $reason,
    ) {
    }
}
