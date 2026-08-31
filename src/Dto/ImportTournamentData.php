<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What the by-hand importer is given.
 *
 * The season is a slug rather than a `Season` so this form speaks the same
 * vocabulary as the bracket importer beside it — including
 * `BracketImportData::UNRANKED`, which this path **refuses**. It is offered
 * and rejected rather than absent: the two forms share a page, and a control
 * that silently lacks an option its neighbour has is how somebody ends up
 * believing they chose it.
 */
final class ImportTournamentData
{
    public function __construct(
        public ?string $season = null,
        public string $title = '',
        public string $date = '',
        public ?string $challongeUrl = null,
        public ?string $knockoutWinner = null,
        public string $playerList = '',
        public string $passphrase = '',
    ) {
    }
}
