<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What an attempt to put a blader on record came to.
 *
 * Only the first writes a row. Nothing here is a failure: a name the league
 * already knows is a replay rather than a mistake, which is what makes
 * `repeat.sh` safe to run twice.
 */
enum CreateBladerResult
{
    case Created;

    /** The league already knows somebody by that name, however it is spelled. */
    case AlreadyOnRecord;

    /** Punctuation and whitespace, with no name underneath. */
    case NotAName;
}
