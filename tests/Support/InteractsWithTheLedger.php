<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\PlayerAliasSource;

/**
 * Assertions about `var/log/command_ledger.sh`.
 *
 * The ledger doubles as a recovery script, so these helpers rebuild the exact
 * command string a replay would run. That mirrors LedgerService deliberately:
 * the tests state the contract independently of the code that implements it.
 */
trait InteractsWithTheLedger
{
    protected static function projectDir(): string
    {
        return dirname(__DIR__, 2);
    }

    protected static function ledgerPath(): string
    {
        return self::projectDir().'/var/log/command_ledger.sh';
    }

    /**
     * The ledger must never gain a line for a change the database rejected,
     * so most failure paths assert that no file was written at all.
     */
    protected static function assertLedgerIsEmpty(): void
    {
        self::assertFileDoesNotExist(
            self::ledgerPath(),
            'Expected no ledger entry to have been written.',
        );
    }

    protected static function assertLedgerRecordsPayment(
        string $seasonSlug,
        string $playerName,
    ): void {
        self::assertLedgerHolds(sprintf(
            'php bin/console app:register-payment %s %s',
            escapeshellarg($seasonSlug),
            escapeshellarg($playerName),
        ));
    }

    protected static function assertLedgerRecordsSeasonCreation(
        string $slug,
        string $name,
        bool $requiresPayment,
    ): void {
        self::assertLedgerHolds(sprintf(
            'php bin/console app:create-season %s %s %s',
            escapeshellarg($slug),
            escapeshellarg($name),
            $requiresPayment ? '1' : '0',
        ));
    }

    protected static function assertLedgerRecordsImport(
        string $title,
        string $heldOn,
        string $sourcePath,
        string $seasonSlug,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
        bool $teamEvent = false,
    ): void {
        $commandLine = sprintf(
            'php bin/console app:import-tournament %s %s %s --season=%s',
            escapeshellarg($title),
            escapeshellarg($heldOn),
            escapeshellarg($sourcePath),
            escapeshellarg($seasonSlug),
        );

        if ($teamEvent) {
            $commandLine .= ' --team';
        }

        if (null !== $challongeUrl) {
            $commandLine .= sprintf(' --challonge=%s', escapeshellarg($challongeUrl));
        }

        if (null !== $knockoutWinner) {
            $commandLine .= sprintf(' --knockout=%s', escapeshellarg($knockoutWinner));
        }

        self::assertLedgerHolds($commandLine);
    }

    protected static function assertLedgerRecordsAlias(
        string $bladerName,
        string $alias,
        ?PlayerAliasSource $source = null,
    ): void {
        $commandLine = sprintf(
            'php bin/console app:alias add %s %s',
            escapeshellarg($bladerName),
            escapeshellarg($alias),
        );

        if (null !== $source && PlayerAliasSource::Manual !== $source) {
            $commandLine .= sprintf(' --source=%s', escapeshellarg($source->value));
        }

        self::assertLedgerHolds($commandLine);
    }

    /**
     * @param list<string> $bladerNames
     */
    protected static function assertLedgerRecordsTeamClaim(
        string $tournamentTitle,
        string $teamName,
        array $bladerNames,
    ): void {
        self::assertLedgerHolds(rtrim(sprintf(
            'php bin/console app:team claim %s %s %s',
            escapeshellarg($tournamentTitle),
            escapeshellarg($teamName),
            implode(' ', array_map(escapeshellarg(...), $bladerNames)),
        )));
    }

    protected static function assertLedgerRecordsArchive(string $slug): void
    {
        self::assertLedgerHolds(sprintf(
            'php bin/console app:archive-challonge %s',
            escapeshellarg($slug),
        ));
    }

    protected static function assertLedgerRecordsAliasRemoval(string $alias): void
    {
        self::assertLedgerHolds(sprintf(
            'php bin/console app:alias remove %s',
            escapeshellarg($alias),
        ));
    }

    /**
     * file_put_contents() cannot write to a directory as though it were a
     * regular file, so a directory in the ledger's place forces the write to
     * fail.
     */
    protected static function blockLedgerWrites(): void
    {
        self::assertTrue(mkdir(self::ledgerPath()));
    }

    /**
     * Handles both a real ledger file and the directory that
     * blockLedgerWrites() leaves behind.
     */
    protected static function removePath(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (is_dir($path)) {
            rmdir($path);
        }
    }

    private static function assertLedgerHolds(string $commandLine): void
    {
        self::assertFileExists(self::ledgerPath());

        self::assertSame(
            $commandLine."\n",
            file_get_contents(self::ledgerPath()),
        );
    }
}
