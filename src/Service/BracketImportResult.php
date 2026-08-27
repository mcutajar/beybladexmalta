<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What a confirmed bracket import came to.
 *
 * Only the first writes anything. Every other case leaves the league exactly
 * as it was — no tournament, no results, no archive, no ledger line — which is
 * the property the preview exists to guarantee.
 */
enum BracketImportResult
{
    case Imported;

    /**
     * The bracket cannot be imported this way at all: a team event, one an
     * event already names, or one with no standings. The preview says which.
     */
    case Refused;

    /**
     * A name the league cannot read is still unanswered, or reaches more than
     * one blader. Nothing is written until every one of them is settled.
     */
    case DecisionsOutstanding;

    /**
     * An alias could not be filed the way it was answered — the spelling is
     * already somebody else's, or the blader it points at has become
     * ambiguous since the preview was rendered.
     */
    case AliasRefused;

    case InvalidDate;

    case SeasonNotFound;

    /**
     * Every entrant was dropped, so there is no finishing order left to score.
     */
    case NoPlacements;
}
