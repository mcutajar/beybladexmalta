<?php

namespace App\Repository;

use App\Entity\Player;

interface PlayerRepositoryInterface
{
    public function findByName(string $name): ?Player;

    public function save(Player $player): void;
}
