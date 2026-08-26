<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One thing a bracket now says that its snapshot does not.
 *
 * The path is where in the file it is — `stages[0].matches[12].score` — and
 * the two values are what each side holds there. A missing side is `null`,
 * which is how something added upstream and something deleted upstream are
 * told apart.
 */
final readonly class SnapshotDifference
{
    /**
     * @param ?string $stored  what the captured file says, or null if it has nothing there
     * @param ?string $fetched what the bracket says now, or null if it no longer has it
     */
    public function __construct(
        public string $path,
        public ?string $stored,
        public ?string $fetched,
    ) {
    }

    public function describe(): string
    {
        return match (true) {
            null === $this->stored => sprintf('%s: the bracket has gained %s.', $this->path, $this->fetched),
            null === $this->fetched => sprintf('%s: the bracket has dropped %s.', $this->path, $this->stored),
            default => sprintf('%s: %s is now %s.', $this->path, $this->stored, $this->fetched),
        };
    }
}
