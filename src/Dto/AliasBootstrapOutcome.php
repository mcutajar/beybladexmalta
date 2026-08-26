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
 *
 * `ledgerStopped` is the other way a run ends early, and it is why this is
 * returned rather than thrown past. Each alias is its own flush and its own
 * ledger write, in its own transaction, so a ledger that stops accepting
 * writes at the seventh of fifteen rolls that seventh back and leaves the
 * first six committed. What was already filed is a fact by then, and an
 * operator told nothing happened would go looking for the wrong problem.
 */
final class AliasBootstrapOutcome
{
    /**
     * @param list<array{spelling: string, blader: string, result: AddAliasResult}> $refused
     * @param ?string                                                               $ledgerStopped why the ledger refused, when it did
     */
    public function __construct(
        public readonly int $written,
        public readonly array $refused,
        public readonly ?string $ledgerStopped = null,
    ) {
    }

    public function wentThrough(): bool
    {
        return [] === $this->refused && null === $this->ledgerStopped;
    }
}
