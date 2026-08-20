<?php

declare(strict_types=1);

namespace App\Tests\Support;

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

    protected static function assertLedgerRecordsImport(
        string $title,
        string $heldOn,
        string $sourcePath,
        string $seasonSlug,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
    ): void {
        $commandLine = sprintf(
            'php bin/console app:import-tournament %s %s %s --season=%s',
            escapeshellarg($title),
            escapeshellarg($heldOn),
            escapeshellarg($sourcePath),
            escapeshellarg($seasonSlug),
        );

        if (null !== $challongeUrl) {
            $commandLine .= sprintf(' --challonge=%s', escapeshellarg($challongeUrl));
        }

        if (null !== $knockoutWinner) {
            $commandLine .= sprintf(' --knockout=%s', escapeshellarg($knockoutWinner));
        }

        self::assertLedgerHolds($commandLine);
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
