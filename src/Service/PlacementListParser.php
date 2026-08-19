<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TournamentPlacement;

class PlacementListParser
{
    /**
     * Parses an ordered placement list into finishing order.
     *
     * Each non-empty line holds a blader name, optionally followed by a
     * comma and the manual bonus points awarded to them.
     *
     * @return list<TournamentPlacement>
     */
    public function parse(string $rawList): array
    {
        $placements = [];

        foreach (preg_split('/\R/', $rawList) ?: [] as $line) {
            $parts = explode(',', $line);
            $playerName = trim($parts[0]);

            if ('' === $playerName) {
                continue;
            }

            $placements[] = new TournamentPlacement(
                playerName: $playerName,
                bonusPoints: isset($parts[1]) ? (int) trim($parts[1]) : 0,
            );
        }

        return $placements;
    }
}
