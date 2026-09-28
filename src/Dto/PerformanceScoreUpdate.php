<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PerformanceResult;

final readonly class PerformanceScoreUpdate
{
    public function __construct(
        public PerformanceResult $result,
        public bool $roundCompleted,
    ) {
    }
}
