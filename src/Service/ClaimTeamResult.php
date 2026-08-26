<?php

declare(strict_types=1);

namespace App\Service;

enum ClaimTeamResult
{
    /** At least one blader was attached, and their placement written. */
    case Claimed;

    /** Everybody named was already in the team — a replay, not a mistake. */
    case AlreadyRecorded;

    /** No blader was named, and an empty claim claims nothing. */
    case NoBladers;

    case TournamentNotFound;

    /** Two events share the title, so it names neither of them. */
    case TournamentIsAmbiguous;

    /**
     * The event has no entrant spelled that way. A claim attaches bladers to a
     * team the bracket recorded; it never invents the team.
     */
    case TeamNotFound;

    /**
     * Nobody is called that. Unlike an import, a claim never creates a blader:
     * it is filed weeks after the event, against a league that already knows
     * everybody who was there.
     */
    case BladerNotFound;

    /** More than one blader is spelled that way, so it names neither. */
    case BladerIsAmbiguous;

    /**
     * The blader already has a placement in this event, under another team.
     * Awarding a second one would score them twice for one evening.
     */
    case BladerAlreadyPlaced;
}
