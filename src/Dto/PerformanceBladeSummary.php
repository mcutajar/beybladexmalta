<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PerformanceBlade;

final readonly class PerformanceBladeSummary
{
    /** @param list<array{match: int, round: int, value: PerformanceResultValue}> $history */
    public function __construct(
        public PerformanceBlade $blade,
        public int $total,
        public int $appearances,
        public int $positive,
        public int $negative,
        public int $zero,
        public int $unused,
        public ?int $best,
        public array $history,
    ) {
    }

    public function average(): ?float
    {
        return 0 === $this->appearances ? null : $this->total / $this->appearances;
    }
}
