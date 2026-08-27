<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeSnapshot;
use App\Dto\PreparedTeamImport;
use App\Dto\TeamImportOutcome;
use App\Dto\TeamPlacement;
use App\Dto\TournamentPlacement;
use App\Entity\Tournament;

/**
 * Replays one complete tournament import from command-line artifacts.
 *
 * Unlike the lower-level import and archive services, this workflow owns the
 * transaction that combines them: scoring rows and archive rows are staged,
 * flushed together, and represented by one snapshot-backed ledger command.
 */
final class ReplayTournamentImportService
{
    public function __construct(
        private TournamentImportService $imports,
        private ChallongeArchiveService $archive,
        private ChallongeSnapshotWriter $snapshots,
        private LedgerService $ledger,
        private FlusherInterface $flusher,
    ) {
    }

    /** @param list<TournamentPlacement> $placements */
    public function import(
        string $title,
        string $heldOn,
        string $seasonSlug,
        array $placements,
        string $sourceFilePath,
        ChallongeSnapshot $snapshot,
        string $snapshotPath,
        string $challongeUrl,
        ?string $knockoutWinner = null,
    ): TournamentImportResult {
        $prepared = $this->imports->prepare(
            title: $title,
            heldOn: $heldOn,
            seasonSlug: $seasonSlug,
            placements: $placements,
            challongeUrl: $challongeUrl,
            knockoutWinner: $knockoutWinner,
        );

        if ($prepared instanceof TournamentImportResult) {
            return $prepared;
        }

        $this->archive->transcribe($prepared, $snapshot);
        $this->commit(
            tournament: $prepared,
            snapshot: $snapshot,
            snapshotPath: $snapshotPath,
            sourceFilePath: $sourceFilePath,
            seasonSlug: $seasonSlug,
            challongeUrl: $challongeUrl,
            knockoutWinner: $knockoutWinner,
        );

        return TournamentImportResult::Imported;
    }

    /** @param list<TeamPlacement> $teams */
    public function importTeamEvent(
        string $title,
        string $heldOn,
        string $seasonSlug,
        array $teams,
        string $sourceFilePath,
        ChallongeSnapshot $snapshot,
        string $snapshotPath,
        string $challongeUrl,
    ): TeamImportOutcome {
        $prepared = $this->imports->prepareTeamEvent(
            title: $title,
            heldOn: $heldOn,
            seasonSlug: $seasonSlug,
            teams: $teams,
            challongeUrl: $challongeUrl,
        );

        if (!$prepared instanceof PreparedTeamImport) {
            return TeamImportOutcome::refused($prepared);
        }

        $this->archive->transcribe($prepared->tournament, $snapshot);
        $this->commit(
            tournament: $prepared->tournament,
            snapshot: $snapshot,
            snapshotPath: $snapshotPath,
            sourceFilePath: $sourceFilePath,
            seasonSlug: $seasonSlug,
            challongeUrl: $challongeUrl,
            teamEvent: true,
        );

        return $prepared->outcome;
    }

    private function commit(
        Tournament $tournament,
        ChallongeSnapshot $snapshot,
        string $snapshotPath,
        string $sourceFilePath,
        string $seasonSlug,
        string $challongeUrl,
        ?string $knockoutWinner = null,
        bool $teamEvent = false,
    ): void {
        $this->flusher->flushThen(function () use ($tournament, $snapshot, $snapshotPath, $sourceFilePath, $seasonSlug, $challongeUrl, $knockoutWinner, $teamEvent): void {
            $this->snapshots->write($snapshot);
            $this->ledger->logTournamentImport(
                title: $tournament->getTitle(),
                heldOn: $tournament->getHeldOn()->format('Y-m-d'),
                sourceFilePath: $sourceFilePath,
                seasonSlug: $seasonSlug,
                challongeUrl: $challongeUrl,
                knockoutWinner: $knockoutWinner,
                teamEvent: $teamEvent,
                snapshotPath: $snapshotPath,
            );
        });
    }
}
