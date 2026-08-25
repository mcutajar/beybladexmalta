<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Everything the bootstrap pass worked out, before any of it is written.
 *
 * The plan is the deliverable, not the write. Sixty-odd assertions about who
 * is who, derived in one pass and applied in one go, is exactly the shape of
 * change that is unpleasant to unpick afterwards — so the command builds this,
 * prints all of it, and only writes when told again.
 *
 * It carries what it could not decide as prominently as what it could:
 * contradictions, the ranks that paired with nothing, and every event that
 * taught it nothing. A pass that reported only its successes would look
 * identical whether it had read sixteen events or two.
 */
final class AliasBootstrapPlan
{
    /**
     * @param list<AliasProposal>      $proposals      in the order the table prints them
     * @param list<AliasContradiction> $contradictions spellings that reached more than one blader
     * @param list<SkippedEvent>       $skipped        events nothing was read out of
     * @param list<string>             $undecided      lines that paired with no rank
     * @param int                      $events         events actually read
     * @param int                      $placements     ranks paired with a line
     * @param int                      $agreed         those where the bracket already spelled the blader's own name
     */
    public function __construct(
        public readonly array $proposals,
        public readonly array $contradictions,
        public readonly array $skipped,
        public readonly array $undecided,
        public readonly int $events,
        public readonly int $placements,
        public readonly int $agreed,
    ) {
    }

    /**
     * The rows --force writes.
     *
     * @return list<AliasProposal>
     */
    public function writable(): array
    {
        return array_values(array_filter(
            $this->proposals,
            static fn (AliasProposal $proposal): bool => $proposal->isWritable(),
        ));
    }

    /**
     * @return list<AliasProposal>
     */
    public function alreadyOnFile(): array
    {
        return array_values(array_filter(
            $this->proposals,
            static fn (AliasProposal $proposal): bool => AliasProposalStatus::AlreadyOnFile === $proposal->status,
        ));
    }

    /**
     * Proposals that are neither new nor already true — a spelling that is
     * somebody else's name, or one an alias already points elsewhere.
     *
     * @return list<AliasProposal>
     */
    public function refused(): array
    {
        return array_values(array_filter(
            $this->proposals,
            static fn (AliasProposal $proposal): bool => !$proposal->isWritable()
                && AliasProposalStatus::AlreadyOnFile !== $proposal->status,
        ));
    }

    /**
     * Whether anything in here wants a person to look at it, which is the
     * question the exit code and the closing message turn on.
     */
    public function needsAPerson(): bool
    {
        return [] !== $this->contradictions
            || [] !== $this->undecided
            || [] !== $this->refused();
    }
}
