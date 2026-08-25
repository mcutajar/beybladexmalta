<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * How a display name reached a blader — or why it did not.
 *
 * Worth carrying rather than collapsing to a boolean, because neither the two
 * ways of succeeding nor the two ways of failing are the same thing. A name
 * that matched a blader's own name needed nobody's judgement; a name that
 * matched an alias is somebody's recorded decision, and when a result looks
 * wrong that is the row to go and read. And a name nobody answers to is a
 * question for whoever was at the event, while a name two bladers answer to is
 * a question about the league's own records.
 */
enum AliasMatch: string
{
    /** The name is the blader's own, give or take spelling. */
    case BladerName = 'blader-name';

    /** The name is a spelling somebody filed against a blader. */
    case Alias = 'alias';

    /**
     * More than one blader is spelled this way. Nothing can be filed against
     * it and nothing may be read out of it until that is settled.
     */
    case Ambiguous = 'ambiguous';

    /** Nobody. The name is a question, and the answer is not ours to invent. */
    case None = 'none';
}
