<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Season;

final class ImportTournamentData
{
    public function __construct(
        public ?Season $season = null,
        public string $title = '',
        public string $date = '',
        public ?string $challongeUrl = null,
        public ?string $knockoutWinner = null,
        public string $playerList = '',
        public string $passphrase = '',
    ) {
    }
}
