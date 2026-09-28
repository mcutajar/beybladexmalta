<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PerformanceTracker;

final readonly class CreatedPerformanceTracker
{
    public function __construct(
        public PerformanceTracker $tracker,
        public string $editToken,
    ) {
    }
}
