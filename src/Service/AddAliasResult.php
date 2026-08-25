<?php

declare(strict_types=1);

namespace App\Service;

enum AddAliasResult
{
    case Added;

    /** Already on file against this blader — a replay, not a mistake. */
    case AlreadyRecorded;

    /** The spelling is what this blader is already called. Nothing to record. */
    case IsTheirOwnName;

    /** The spelling is another blader's actual name, so it cannot be an alias. */
    case IsAnotherBladersName;

    /** Another blader already answers to it. */
    case TakenByAnotherBlader;

    /**
     * Nobody is called that. An alias never creates a blader — that is the
     * whole rule, and it holds here as much as it does at import time.
     */
    case BladerNotFound;

    /**
     * More than one blader is called that, so naming one of them is not
     * something this can do on somebody's behalf.
     */
    case BladerIsAmbiguous;

    /** Punctuation and an invitation suffix, with no name underneath. */
    case NotAName;
}
