<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * How a standings row was joined to the entrant it is about.
 *
 * Worth keeping rather than throwing away, because the two ways are not
 * equally trustworthy. The match-id intersection is a fact about who played
 * what; the name is a guess that happens to be right, and a bracket where a
 * lot of rows fell back to it is a bracket to look at.
 */
enum ChallongeJoin: string
{
    /**
     * Every match in the row's match-history cell was played by exactly one
     * entrant in common, and that is who the row is about.
     */
    case MatchIds = 'match_ids';

    /**
     * The row's name, or the account Challonge rendered in place of it,
     * matched exactly one entrant. Needed where the intersection cannot
     * decide: a row with one match narrows to its two players, and the
     * standings tables of a one-stage bracket carry no match history at all.
     */
    case Name = 'name';

    /**
     * Neither could decide. The row is kept, because a standings table with a
     * row missing is worse than a row with nobody attached to it.
     */
    case None = 'none';
}
