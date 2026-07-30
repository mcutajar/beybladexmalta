<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Season;

final class RegisterPaymentData
{
    public function __construct(
        public ?Season $season = null,
        public string $playerName = '',
        public string $passphrase = '')
    {
    }
}
