<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Player;

/**
 * Every spelling the league knows, normalised, read once.
 *
 * Resolving a single name is a lookup, but resolving one that misses means
 * comparing it against everything — and a bracket arrives as forty names at a
 * time. So the two tables are read in full, once, and passed around as this.
 * Seventy-six bladers and a few hundred aliases is small enough that the
 * honest thing is to hold them in memory rather than to build an index in
 * Postgres for a console command.
 */
final class AliasIndex
{
    /**
     * @param array<string, list<Player>> $bladers normalised blader name => whoever bears it
     * @param array<string, Player>       $aliases normalised alias => the blader it points at
     */
    public function __construct(
        private readonly array $bladers,
        private readonly array $aliases,
    ) {
    }

    /**
     * Whoever is called this.
     *
     * Normally nobody or exactly one person. Two is possible — `Rip N' Burst`
     * and `Ripnburst` are distinct rows in a table that is unique on the raw
     * name — and it is why this returns a list rather than a blader. A caller
     * that got handed one of the two would be picking, and picking wrong half
     * the time.
     *
     * @return list<Player>
     */
    public function bladersCalled(string $normalised): array
    {
        return $this->bladers[$normalised] ?? [];
    }

    public function aliasedTo(string $normalised): ?Player
    {
        return $this->aliases[$normalised] ?? null;
    }

    /**
     * Every known spelling paired with whose it is, for the suggestion pass.
     * A spelling two bladers share appears once for each of them.
     *
     * @return list<array{spelling: string, player: Player}>
     */
    public function spellings(): array
    {
        $spellings = [];

        foreach ($this->bladers as $normalised => $players) {
            foreach ($players as $player) {
                $spellings[] = ['spelling' => (string) $normalised, 'player' => $player];
            }
        }

        foreach ($this->aliases as $normalised => $player) {
            $spellings[] = ['spelling' => (string) $normalised, 'player' => $player];
        }

        return $spellings;
    }
}
