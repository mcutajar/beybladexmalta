<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PerformanceMatch;

final class PerformanceMatchData
{
    public function __construct(
        public string $opponent = '',
        public string $finalScore = '',
        public string $stage = '',
        public string $notes = '',
        public ?\DateTimeImmutable $playedAt = null,
    ) {
    }

    public static function fromMatch(PerformanceMatch $match): self
    {
        return new self(
            $match->getOpponent() ?? '',
            $match->getFinalScore() ?? '',
            $match->getStage() ?? '',
            $match->getNotes() ?? '',
            $match->getPlayedAt(),
        );
    }
}
