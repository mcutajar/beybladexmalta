<?php

declare(strict_types=1);

namespace App\Dto;

final class PerformanceBladeData
{
    public function __construct(
        public string $displayName = '',
        public string $blade = '',
        public string $ratchet = '',
        public string $bit = '',
        public string $colour = '',
    ) {
    }
}
