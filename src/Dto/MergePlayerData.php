<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Player;

final class MergePlayerData
{
    public function __construct(
        public ?Player $from = null,
        public ?Player $into = null,
        public string $passphrase = '',
        public bool $confirm = false,
    ) {
    }
}
