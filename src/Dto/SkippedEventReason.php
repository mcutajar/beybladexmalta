<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Why an event taught the bootstrap pass nothing.
 *
 * Every one of these is printed. An event that is quietly left out of a pass
 * that claims to have read everything already imported is the one way this
 * command could be wrong without looking wrong.
 */
enum SkippedEventReason: string
{
    /** Imported from a placement list with no bracket behind it. */
    case NoBracket = 'no bracket';

    /** The URL it was imported with names no Challonge bracket. */
    case NotABracketUrl = 'not a bracket URL';

    /** The bracket has not been captured yet, so there is nothing to read. */
    case NotCaptured = 'not captured';

    /** A snapshot that is there and cannot be read. */
    case Unreadable = 'unreadable';

    /** Captured without `show_standings=1`, so it ranks nobody. */
    case NoStandings = 'no standings';

    /**
     * A 2v2 event, whose entrants are teams rather than bladers.
     *
     * Nothing in it pairs a spelling with a person. A team name belongs to
     * the two bladers in it and the bracket does not say who they were, so
     * the event was imported as a Player A list and a Player B list — and
     * pairing rank *n* of the bracket with line *n* of either would file
     * `TheFireBlades` against whichever blader happened to be written first.
     *
     * The lists themselves are worse than useless here. Where the roster was
     * not known they were padded to ten lines with names that are nobody:
     * `JG1` and `JG2` for the two people in team `JG`, and the literal `-`,
     * `--` and `---`. Those are rows in `players` and they are not bladers.
     * Team `melhina` is the same fact with the placeholder left out — it
     * finished eleventh and was never imported at all.
     *
     * So a team event is skipped whole rather than filtered, and its rosters
     * are #67's, which gives an unclaimed team a record of its own and takes
     * the five phantom players back out.
     */
    case TeamEvent = 'team event';

    /** Two rows at one rank, on one side or the other. */
    case RanksAreNotAnOrder = 'ranks are not an order';

    /** A shape there is no rule for yet — pools, most likely. */
    case Unsupported = 'unsupported bracket';

    /**
     * The sentence printed under the table. A reason nobody can act on is a
     * reason nobody should have to decode.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::NoBracket => 'it was imported from a placement list alone, so there is no bracket spelling to pair with.',
            self::NotABracketUrl => 'the URL it was imported with names no Challonge bracket.',
            self::NotCaptured => 'its bracket has not been captured yet. Run app:fetch-challonge on it first.',
            self::Unreadable => 'its snapshot could not be read.',
            self::NoStandings => 'its snapshot ranks nobody, so no rank pairs with any line.',
            self::TeamEvent => 'it is a 2v2 event: the entrants are teams, and a team name belongs to two bladers rather than one. Its rosters are not this command\'s to work out.',
            self::RanksAreNotAnOrder => 'two entrants share a rank, so line n and rank n are not the same claim.',
            self::Unsupported => 'its bracket has a shape there is no finishing order for yet.',
        };
    }
}
