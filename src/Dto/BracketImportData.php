<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Season;

/**
 * What is typed to start a bracket import: a link, and the three things the
 * bracket does not say.
 *
 * Challonge knows the entrants, the matches and the finishing order. It does
 * not know what the league calls the evening, when the league says it was held
 * — the bracket carries timestamps for when it was *created*, which is not the
 * same thing — or which season it counts towards. Those four fields are
 * settled before the network call and never re-read from the browser
 * afterwards.
 */
final class BracketImportData
{
    public function __construct(
        public ?Season $season = null,
        public string $challongeUrl = '',
        public string $title = '',
        public string $date = '',
    ) {
    }
}
