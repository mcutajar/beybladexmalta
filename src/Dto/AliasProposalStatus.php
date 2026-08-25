<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What the alias table already has to say about a spelling the bootstrap pass
 * worked out for itself.
 *
 * The pass derives a pair — this spelling, that blader — and then has to ask
 * whether writing it is the same claim somebody has already made, a different
 * one, or new. Only the last is written, and the other three are the reason
 * the command prints its whole table before it is allowed to touch anything.
 */
enum AliasProposalStatus: string
{
    /** Nobody has filed this spelling. It is what --force writes. */
    case Unrecorded = 'new';

    /**
     * Already on file against this blader — so a second run of the pass has
     * nothing to do, which is what makes re-running it a no-op.
     */
    case AlreadyOnFile = 'already on file';

    /**
     * The spelling is some other blader's actual name. Filing it would point
     * one blader's name at another, and if the two are one person that is a
     * merge rather than an alias.
     */
    case IsAnotherBladersName = 'another blader\'s name';

    /** An alias already points this spelling somewhere else. */
    case TakenByAnotherBlader = 'points elsewhere';

    /**
     * Whether --force writes it. Everything else is either already true or a
     * contradiction, and neither is this pass's to settle.
     */
    public function isWritable(): bool
    {
        return self::Unrecorded === $this;
    }
}
