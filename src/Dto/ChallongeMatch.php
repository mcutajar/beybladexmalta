<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One match, with its per-game scorelines kept.
 *
 * `games` is every game played — `[[7, 4]]` for a single game, `[[4, 7],
 * [7, 3], [1, 7]]` for a best-of-three — and `score` is what Challonge shows
 * as the result, which for a multi-game match is games won rather than points.
 */
final class ChallongeMatch
{
    /**
     * @param list<list<int>> $games       every game played, in order
     * @param list<int>       $score       the result Challonge shows
     * @param bool            $consolation whether the match decided placing only — in practice the
     *                                     third-place playoff, which is played after the final and
     *                                     must never be read as it
     */
    public function __construct(
        public readonly int $id,
        public readonly int $round,
        public readonly ?string $identifier,
        public readonly string $state,
        public readonly ?int $player1Id,
        public readonly ?int $player2Id,
        public readonly array $games,
        public readonly array $score,
        public readonly ?int $winnerId,
        public readonly ?int $loserId,
        public readonly bool $forfeited,
        public readonly bool $consolation,
    ) {
    }

    /**
     * Whether the match was actually contested.
     *
     * A forfeit is `complete` and has a winner, but nobody played it, so it is
     * not a match anybody spun for. The one match in the corpus that finished
     * `complete` with no winner and a 0-0 scoreline *is* counted: Challonge
     * displayed it as played, and a snapshot does not get to disagree with the
     * bracket it transcribed.
     */
    public function wasPlayed(): bool
    {
        return 'complete' === $this->state && !$this->forfeited;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'round' => $this->round,
            'identifier' => $this->identifier,
            'state' => $this->state,
            'player1' => $this->player1Id,
            'player2' => $this->player2Id,
            'games' => $this->games,
            'score' => $this->score,
            'winner' => $this->winnerId,
            'loser' => $this->loserId,
            'forfeited' => $this->forfeited,
            'consolation' => $this->consolation,
        ];
    }
}
