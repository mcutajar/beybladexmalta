<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Tournament;
use App\Service\TournamentImportResult;

final readonly class TournamentImportOutcome
{
    public function __construct(
        public TournamentImportResult $result,
        public ?Tournament $tournament = null,
    ) {
    }
}
