<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * A fetched bracket and the four things that were typed alongside it.
 *
 * Everything the confirm needs and none of it posted back. The bracket, the
 * title, the date and the season are settled before the network call and kept
 * on the server until somebody approves or abandons the draft — so a confirm
 * carries only choices, and no field on the page can assert a fact.
 */
final readonly class BracketDraft
{
    public function __construct(
        public ChallongeSnapshot $snapshot,
        public string $challongeUrl,
        public string $title,
        public string $heldOn,
        public string $seasonSlug,
    ) {
    }
}
