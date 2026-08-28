<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlayerAliasSource;
use App\Exception\LedgerWriteException;
use Symfony\Component\HttpKernel\KernelInterface;

class LedgerService
{
    public function __construct(
        private KernelInterface $kernel,
    ) {
    }

    public function logRegistrationAttempt(
        string $seasonSlug,
        string $playerName,
    ): void {
        $this->append(
            sprintf(
                'php bin/console app:register-payment %s %s',
                escapeshellarg($seasonSlug),
                escapeshellarg($playerName),
            ),
        );
    }

    public function logSeasonCreation(
        string $slug,
        string $name,
        bool $requiresPayment,
    ): void {
        $this->append(
            sprintf(
                'php bin/console app:create-season %s %s %s',
                escapeshellarg($slug),
                escapeshellarg($name),
                $requiresPayment ? '1' : '0',
            ),
        );
    }

    /**
     * @param bool $teamEvent a 2v2 event, whose file is a roster rather than a
     *                        placement list — declared rather than detected, so
     *                        the replay has to be told the same way
     */
    public function logTournamentImport(
        string $title,
        string $heldOn,
        string $sourceFilePath,
        string $seasonSlug,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
        bool $teamEvent = false,
        ?string $snapshotPath = null,
    ): void {
        $this->append($this->tournamentImportCommand(
            title: $title,
            heldOn: $heldOn,
            sourceFilePath: $sourceFilePath,
            seasonSlug: $seasonSlug,
            challongeUrl: $challongeUrl,
            knockoutWinner: $knockoutWinner,
            teamEvent: $teamEvent,
            snapshotPath: $snapshotPath,
        ));
    }

    /**
     * The same line, built and handed back rather than appended.
     *
     * The import preview shows the operator exactly what is about to land in
     * `repeat.sh` before they confirm it, and a screen that composed its own
     * approximation of the command would drift from the one that is written
     * the moment either changed. So the construction stays here — this class
     * owns it — and the preview borrows it.
     */
    public function tournamentImportCommand(
        string $title,
        string $heldOn,
        string $sourceFilePath,
        string $seasonSlug,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
        bool $teamEvent = false,
        ?string $snapshotPath = null,
    ): string {
        $commandLine = sprintf(
            'php bin/console app:import-tournament %s %s %s --season=%s',
            escapeshellarg($title),
            escapeshellarg($heldOn),
            escapeshellarg($sourceFilePath),
            escapeshellarg($seasonSlug),
        );

        if ($teamEvent) {
            $commandLine .= ' --team';
        }

        if (null !== $challongeUrl && '' !== $challongeUrl) {
            $commandLine .= sprintf(
                ' --challonge=%s',
                escapeshellarg($challongeUrl),
            );
        }

        if (null !== $snapshotPath && '' !== $snapshotPath) {
            $commandLine .= sprintf(' --snapshot=%s', escapeshellarg($snapshotPath));
        }

        if (null !== $knockoutWinner && '' !== $knockoutWinner) {
            $commandLine .= sprintf(
                ' --knockout=%s',
                escapeshellarg($knockoutWinner),
            );
        }

        return $commandLine;
    }

    /**
     * The alias table is rebuilt the same way everything else is: by replaying
     * repeat.sh into an empty schema. An alias somebody typed and nobody
     * recorded here survives exactly until the next schema change.
     *
     * The blader is written under the name the database holds rather than
     * whatever was typed, so a replay files the alias against the same person
     * however the original command spelled them. The source is only named when
     * it is not the default, which keeps a hand-typed line short and makes the
     * seeded ones visibly seeded.
     */
    public function logAliasAdded(
        string $bladerName,
        string $alias,
        PlayerAliasSource $source = PlayerAliasSource::Manual,
    ): void {
        $this->append($this->aliasAddedCommand($bladerName, $alias, $source));
    }

    /**
     * Claiming a team changes a historical standing — it writes placements
     * against an event that was imported weeks ago and awards that rank's
     * points — so it is a replayable line like every other admin action, and
     * it replays after the import that created the team.
     *
     * The bladers are written under the names the database holds rather than
     * whatever was typed, so a rebuilt league attaches the same people.
     *
     * @param list<string> $bladerNames
     */
    public function logTeamClaimed(
        string $tournamentTitle,
        string $teamName,
        array $bladerNames,
    ): void {
        $this->append(rtrim(sprintf(
            'php bin/console app:team claim %s %s %s',
            escapeshellarg($tournamentTitle),
            escapeshellarg($teamName),
            implode(' ', array_map(escapeshellarg(...), $bladerNames)),
        )));
    }

    /**
     * Archiving a bracket is replayable in a way fetching one is not: it reads
     * `var/data/challonge/<slug>.json`, which is tracked by git, so a replay
     * rebuilds every match of every event without ever asking Challonge
     * whether the bracket still exists.
     *
     * It replays after the import that created the tournament, which is what
     * the line finds the tournament by — the bracket the import recorded.
     *
     * A second line for a bracket already archived costs nothing. Unlike an
     * import, which inserts a fresh set of results every time it runs, an
     * archive looks every row up by its natural key first.
     */
    public function logChallongeArchived(string $slug): void
    {
        $this->append($this->challongeArchiveCommand($slug));
    }

    /**
     * The archive line, handed back rather than appended, for the preview.
     */
    public function challongeArchiveCommand(string $slug): string
    {
        return sprintf(
            'php bin/console app:archive-challonge %s',
            escapeshellarg($slug),
        );
    }

    /**
     * A blader who was never on a placement list.
     *
     * The import screen is the only thing that creates one deliberately, and
     * the ones it creates are usually the reason it had to: fifty-two
     * spellings across the captured brackets reach nobody and every one of
     * them finished eleventh or worse, so they are archived rather than
     * scored and never appear in a `var/data/imports/*.txt`. Without a line of
     * their own they would exist until the next schema rebuild and then
     * quietly stop existing, taking every match attached to them with them.
     *
     * It replays before the import and the aliases that point at it, which is
     * the order the screen writes them in.
     */
    public function logBladerCreated(string $name): void
    {
        $this->append($this->createBladerCommand($name));
    }

    public function createBladerCommand(string $name): string
    {
        return sprintf(
            'php bin/console app:create-blader %s',
            escapeshellarg($name),
        );
    }

    /**
     * The alias line, handed back rather than appended, for the preview.
     */
    public function aliasAddedCommand(
        string $bladerName,
        string $alias,
        PlayerAliasSource $source = PlayerAliasSource::Manual,
    ): string {
        $commandLine = sprintf(
            'php bin/console app:alias add %s %s',
            escapeshellarg($bladerName),
            escapeshellarg($alias),
        );

        if (PlayerAliasSource::Manual !== $source) {
            $commandLine .= sprintf(' --source=%s', escapeshellarg($source->value));
        }

        return $commandLine;
    }

    public function logAliasRemoved(string $alias): void
    {
        $this->append(
            sprintf(
                'php bin/console app:alias remove %s',
                escapeshellarg($alias),
            ),
        );
    }

    public function logAliasSuggestionRejected(string $bladerName, string $spelling): void
    {
        $this->append($this->aliasSuggestionRejectedCommand($bladerName, $spelling));
    }

    public function aliasSuggestionRejectedCommand(string $bladerName, string $spelling): string
    {
        return sprintf('php bin/console app:alias-rejection reject %s %s', escapeshellarg($bladerName), escapeshellarg($spelling));
    }

    public function logAliasSuggestionAllowed(string $bladerName, string $spelling): void
    {
        $this->append(sprintf(
            'php bin/console app:alias-rejection allow %s %s',
            escapeshellarg($bladerName),
            escapeshellarg($spelling),
        ));
    }

    private function append(string $commandLine): void
    {
        /*
         * var/log/ is not tracked by git, so on a fresh checkout the directory
         * does not exist yet and the first admin action would fail here rather
         * than at anything to do with the ledger itself.
         */
        $directory = $this->kernel->getProjectDir().'/var/log';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new LedgerWriteException(sprintf('Failed to create the ledger directory "%s".', $directory));
        }

        $logFilePath = $directory.'/command_ledger.sh';

        $written = @file_put_contents(
            $logFilePath,
            $commandLine."\n",
            FILE_APPEND | LOCK_EX,
        );

        if (false === $written) {
            throw new LedgerWriteException('Failed to write to ledger file.');
        }
    }
}
