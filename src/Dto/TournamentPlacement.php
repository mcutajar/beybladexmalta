<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class TournamentPlacement
{
    public function __construct(
        public string $playerName,
        public int $bonusPoints = 0,
    ) {
    }
}
