<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * How a display name reached a blader — or that it did not.
 *
 * Worth carrying rather than collapsing to a boolean, because the two ways of
 * succeeding are not equally interesting. A name that matched a blader's own
 * name needed nobody's judgement; a name that matched an alias is somebody's
 * recorded decision, and when a result looks wrong that is the row to go and
 * read.
 */
enum AliasMatch: string
{
    /** The name is the blader's own, give or take spelling. */
    case BladerName = 'blader-name';

    /** The name is a spelling somebody filed against a blader. */
    case Alias = 'alias';

    /** Nobody. The name is a question, and the answer is not ours to invent. */
    case None = 'none';
}
