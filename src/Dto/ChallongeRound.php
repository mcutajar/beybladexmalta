<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A round within a stage. The title is Challonge's own — "Round 3",
 * "Semifinals", "Finals" — and is worth keeping because it is what a
 * tournament page will want to print.
 */
final class ChallongeRound
{
    public function __construct(
        public readonly int $number,
        public readonly ?string $title,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'title' => $this->title,
        ];
    }
}
