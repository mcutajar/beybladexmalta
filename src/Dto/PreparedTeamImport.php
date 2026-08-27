<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Tournament;

/**
 * A team import staged in Doctrine but not flushed yet.
 */
final readonly class PreparedTeamImport
{
    public function __construct(
        public Tournament $tournament,
        public TeamImportOutcome $outcome,
    ) {
    }
}
