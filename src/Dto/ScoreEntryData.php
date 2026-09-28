<?php

declare(strict_types=1);

namespace App\Dto;

final class ScoreEntryData
{
    public function __construct(
        public string $result = PerformanceResultValue::NotRecorded->value,
    ) {
    }
}
