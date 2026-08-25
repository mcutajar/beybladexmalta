<?php

declare(strict_types=1);

namespace App\Dto;

use App\Service\AddAliasResult;

/**
 * What applying a plan actually did.
 *
 * A plan is built against the tables as they were a moment ago, and the write
 * goes through `AliasService` — which checks the same rules again, against the
 * tables as they are. Normally the two agree and every writable row lands. The
 * refusals are kept rather than counted so that a disagreement between the two
 * is readable rather than a number that is one short.
 */
final class AliasBootstrapOutcome
{
    /**
     * @param list<array{spelling: string, blader: string, result: AddAliasResult}> $refused
     */
    public function __construct(
        public readonly int $written,
        public readonly array $refused,
    ) {
    }

    public function wentThrough(): bool
    {
        return [] === $this->refused;
    }
}
