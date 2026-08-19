<?php

declare(strict_types=1);

namespace App\Service;

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

    public function logTournamentImport(
        string $title,
        string $heldOn,
        string $sourceFilePath,
        string $seasonSlug,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
    ): void {
        $commandLine = sprintf(
            'php bin/console app:import-tournament %s %s %s --season=%s',
            escapeshellarg($title),
            escapeshellarg($heldOn),
            escapeshellarg($sourceFilePath),
            escapeshellarg($seasonSlug),
        );

        if (null !== $challongeUrl && '' !== $challongeUrl) {
            $commandLine .= sprintf(
                ' --challonge=%s',
                escapeshellarg($challongeUrl),
            );
        }

        if (null !== $knockoutWinner && '' !== $knockoutWinner) {
            $commandLine .= sprintf(
                ' --knockout=%s',
                escapeshellarg($knockoutWinner),
            );
        }

        $this->append($commandLine);
    }

    private function append(string $commandLine): void
    {
        $logFilePath = $this->kernel->getProjectDir()
            .'/var/log/command_ledger.sh';

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
