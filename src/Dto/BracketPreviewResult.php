<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Whether a captured bracket can be imported from a URL at all.
 *
 * Only the first answers yes. The other three are refusals, and each of them
 * is a bracket that is fine — it is this way in that cannot read it.
 */
enum BracketPreviewResult
{
    case Ready;

    /**
     * A 2v2 bracket, whose entrants are teams.
     *
     * A team name is two bladers and the bracket never says which two, so
     * there is nothing here to resolve and no roster to derive. Those events
     * are imported from a roster file somebody writes — `app:import-tournament
     * ... --team` — and this screen would have to invent the half it is
     * missing.
     */
    case TeamEvent;

    /**
     * An event on record already names this bracket.
     *
     * Refused rather than imported again, because `app:import-tournament` has
     * no guard of its own: a second import inserts a fresh tournament and a
     * full set of results, silently doubling the event on the leaderboard.
     * Re-reading a bracket that changed upstream is `app:fetch-challonge`
     * followed by `app:archive-challonge`, which repair rather than duplicate.
     */
    case AlreadyImported;

    /**
     * The bracket has no standings table, so it states no finishing order.
     *
     * `ChallongeFetcher` refuses to capture one at all, so this is only
     * reachable from a snapshot captured before that check existed.
     */
    case NoStandings;
}
