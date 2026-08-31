<?php

declare(strict_types=1);

namespace App\Dto;

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
 *
 * The season carries **three** states rather than two, and keeping the middle
 * one apart from the first is the whole of #91's entry form:
 *
 * - `null` — nothing was chosen. A `ChoiceType` with a placeholder really does
 *   hand back null however hard `empty_data` is leaned on, and that is a
 *   refusal rather than a decision. It must never silently mean unranked.
 * - `self::UNRANKED` — chosen: this event scores nothing.
 * - anything else — a season slug.
 *
 * A slug rather than a `Season`, because "no season" is a value the entity
 * type cannot express and a second control to express it would be a second
 * thing to mis-tap.
 */
final class BracketImportData
{
    /**
     * The one option in the season list that is not a season.
     *
     * Not a slug anybody could take: `SeasonRepository::findBySlug()` is asked
     * before this value is ever compared, so a season really called `unranked`
     * would still be found — but the constant is here rather than typed out at
     * each site so the two spellings cannot come apart.
     */
    public const string UNRANKED = 'unranked';

    public function __construct(
        public ?string $season = null,
        public string $challongeUrl = '',
        public string $title = '',
        public string $date = '',
    ) {
    }
}
