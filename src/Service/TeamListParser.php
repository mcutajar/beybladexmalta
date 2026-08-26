<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TeamPlacement;

/**
 * Reads a team event's roster file.
 *
 * One line per entrant, in finishing order, the team name and then the bladers
 * in it:
 *
 *     irmied u gebel: Butcher + Obelix
 *     JG:
 *     bye
 *
 * `+` between the members rather than a comma, because `PlacementListParser`
 * spends the comma on manual bonus points and a team event awards none — but
 * the two files sit in the same directory and a line that means something
 * different in each is worth not writing.
 *
 * A trailing colon with nothing after it is an unclaimed team, and says so on
 * purpose: `JG` finished tenth and nobody knows who was in it. A line with no
 * colon at all parses the same way, which is what lets `bye` be written as the
 * bracket wrote it and dropped further down.
 *
 * Rank is the line's position among the non-empty lines. Dropping an entrant
 * happens after this, so the rank a line was typed at is the rank it keeps.
 */
class TeamListParser
{
    private const string ROSTER = ':';

    private const string BETWEEN_MEMBERS = '+';

    /**
     * @return list<TeamPlacement>
     */
    public function parse(string $rawList): array
    {
        $teams = [];
        $rank = 0;

        foreach (preg_split('/\R/', $rawList) ?: [] as $line) {
            [$name, $roster] = array_pad(explode(self::ROSTER, $line, 2), 2, '');

            $name = trim($name);

            if ('' === $name) {
                continue;
            }

            $teams[] = new TeamPlacement(
                rank: ++$rank,
                teamName: $name,
                memberNames: $this->members((string) $roster),
            );
        }

        return $teams;
    }

    /**
     * @return list<string>
     */
    private function members(string $roster): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode(self::BETWEEN_MEMBERS, $roster)),
            static fn (string $name): bool => '' !== $name,
        ));
    }
}
