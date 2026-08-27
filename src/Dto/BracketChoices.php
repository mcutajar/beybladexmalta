<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Everything about a previewed import that a person is allowed to change.
 *
 * Three things, and nothing else: who the unreadable names are, what order the
 * entrants finished in, and who won the cut. The bracket, the title, the date
 * and the season are settled before the fetch and are not re-read from the
 * browser afterwards — which is what makes the confirm safe without signing
 * anything. There is no payload to tamper with, only a choice between options
 * the preview itself produced.
 */
final readonly class BracketChoices
{
    /**
     * @param array<int, int> $order          the standings row the entrant came from => where it should
     *                                        finish instead; a row nobody moved keeps its own place
     * @param ?string         $knockoutWinner the blader who won the cut, or '' for nobody; null leaves
     *                                        the one the bracket's last final-stage match names
     */
    public function __construct(
        public BracketAnswers $answers = new BracketAnswers(),
        public array $order = [],
        public ?string $knockoutWinner = null,
    ) {
    }

    /**
     * Where a standings row should finish, if it was moved.
     */
    public function placeOf(int $row, int $fallback): int
    {
        return $this->order[$row] ?? $fallback;
    }
}
