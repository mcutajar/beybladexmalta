<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What an attempt to archive a bracket came to.
 *
 * Only the first is a failure of nothing; the other three are refusals, and
 * none of them writes a row.
 */
enum ChallongeArchiveResult
{
    case Archived;

    /**
     * A 2v2 event, which archives its entrants and nothing else.
     *
     * Not an error and not a gap. A team "match" is a set of individual
     * matchups and Challonge records only the aggregate — `games
     * [[7,2],[5,7],[5,7]] score [1,2]` is three matchups and the sets won,
     * with nothing saying which half of either team played which — so there
     * is no honest blader-level row to write. The entrants are teams, so a
     * `TournamentParticipant` for one would be a category error. The teams
     * themselves are already on record, written by the import.
     */
    case TeamEvent;

    /**
     * The tournament does not say which bracket it came from.
     *
     * Refused rather than archived anyway, because the archive has to be
     * replayable: `repeat.sh` rebuilds the league from an empty schema, and
     * the replay line names a bracket slug. A tournament that names no bracket
     * could be archived once and never again.
     */
    case NoBracketRecorded;

    /**
     * The tournament names a different bracket from the one it was handed.
     */
    case NotThisBracket;
}
