<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * The fixed half of a confirmed import.
 *
 * The decisions are a variable-length answer set keyed by names the server
 * produced, so they are read off the request rather than bent into form
 * fields. What is left is two things of fixed shape: which bracket is being
 * confirmed, and the passphrase — which is checked here and not at the fetch,
 * because the fetch writes nothing.
 *
 * There is no field for the knockout winner. The bracket names it — the winner
 * of the last match of the cut — and it reproduced the hand-typed argument on
 * every event that had one, so asking would be asking a question already
 * answered.
 */
final class BracketConfirmData
{
    public function __construct(
        public string $slug = '',
        public string $passphrase = '',
    ) {
    }
}
