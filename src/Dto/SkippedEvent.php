<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * An event the bootstrap pass read nothing out of, and why.
 */
final class SkippedEvent
{
    public function __construct(
        public readonly string $title,
        public readonly ?string $slug,
        public readonly SkippedEventReason $reason,
        public readonly ?string $detail = null,
    ) {
    }

    public function bracket(): string
    {
        return $this->slug ?? '—';
    }
}
