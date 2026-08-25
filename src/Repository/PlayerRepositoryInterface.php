<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Player;

interface PlayerRepositoryInterface
{
    public function findByName(string $name): ?Player;

    /**
     * Every blader there is.
     *
     * Reasonable because there are seventy-six of them and the league is not
     * going to gain a thousand: resolving a Challonge display name means
     * comparing it against all of them, so the alternative to reading the
     * table is asking the database to normalise spellings, which it cannot do.
     *
     * @return list<Player>
     */
    public function findAll(): array;

    public function save(Player $player): void;
}
