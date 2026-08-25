<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A spelling two evenings disagreed about.
 *
 * One bracket ranked `Jean` where the import recorded one blader, another
 * ranked `Jean` where it recorded a different one. Both are on record and
 * neither is more recent in any sense that means anything, so the pass writes
 * nothing and says so — a bootstrap that averaged its evidence would file the
 * wrong half of somebody's career under a spelling that then resolves
 * silently for ever.
 *
 * It is not always somebody's mistake. Two bladers who spell themselves alike
 * produce exactly this, and so does a placement list typed against the wrong
 * bracket. Which of those it is takes a person, which is the point.
 */
final class AliasContradiction
{
    /**
     * @param array<string, list<string>> $claims blader name => the events that named them
     */
    public function __construct(
        public readonly string $spelling,
        public readonly string $normalised,
        public readonly array $claims,
    ) {
    }

    /**
     * @return list<string>
     */
    public function bladers(): array
    {
        return array_map(strval(...), array_keys($this->claims));
    }

    /**
     * The line a person reads: who it reached, and where each claim came from.
     */
    public function problem(): string
    {
        return sprintf(
            '"%s" is %s.',
            $this->spelling,
            implode('; and ', array_map(
                static fn (string $blader, array $events): string => sprintf('%s in %s', $blader, implode(', ', $events)),
                array_map(strval(...), array_keys($this->claims)),
                array_values($this->claims),
            )),
        );
    }
}
