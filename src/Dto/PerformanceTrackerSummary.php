<?php

declare(strict_types=1);

namespace App\Dto;

final readonly class PerformanceTrackerSummary
{
    /** @param list<PerformanceBladeSummary> $blades */
    public function __construct(
        public array $blades,
        public int $total,
        public int $appearances,
        public int $completedMatches,
        public int $recordedMatches,
    ) {
    }

    public function average(): ?float
    {
        return 0 === $this->appearances ? null : $this->total / $this->appearances;
    }

    public function forLane(PerformanceBladeLane $lane): PerformanceBladeSummary
    {
        foreach ($this->blades as $summary) {
            if ($summary->blade->getLane() === $lane) {
                return $summary;
            }
        }

        throw new \LogicException(sprintf('Blade lane %s has no summary.', $lane->value));
    }
}
