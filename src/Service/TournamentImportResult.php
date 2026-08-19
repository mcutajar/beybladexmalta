<?php

declare(strict_types=1);

namespace App\Service;

enum TournamentImportResult
{
    case Imported;
    case SeasonNotFound;
    case InvalidDate;
    case NoPlacements;
}
