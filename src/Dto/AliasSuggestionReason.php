<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Why a blader is being offered for a name that did not resolve.
 *
 * A suggestion is never applied, so the reason is the whole of its value: it
 * is what the person deciding reads before saying yes. `Obelisk` is offered
 * for `Obelix` and must be offered *as* a two-letter difference, because that
 * is exactly the fact that makes it a bad idea to accept without thinking.
 */
enum AliasSuggestionReason: string
{
    /**
     * The bracket rendered this blader's Challonge account in place of their
     * name. An exact hit, and still only a suggestion: an account is a login,
     * and a household that shares one shares it between two bladers.
     */
    case ChallongeAccount = 'challonge-account';

    /** A known spelling a few edits away. */
    case Spelling = 'spelling';

    /** One spelling is contained in the other — a suffix, a prefix, an `Il-`. */
    case PartOfAKnownName = 'part-of-a-known-name';

    /**
     * How much weight to give this reason when ordering a shortlist. Lower is
     * stronger; an exact hit on an account outranks a near-miss on spelling,
     * which outranks a shared stem.
     */
    public function ordinal(): int
    {
        return match ($this) {
            self::ChallongeAccount => 0,
            self::Spelling => 1,
            self::PartOfAKnownName => 2,
        };
    }
}
