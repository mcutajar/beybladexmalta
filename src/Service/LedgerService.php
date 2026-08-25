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
        $commandLine = sprintf(
            'php bin/console app:alias add %s %s',
            escapeshellarg($bladerName),
            escapeshellarg($alias),
        );

        if (PlayerAliasSource::Manual !== $source) {
            $commandLine .= sprintf(' --source=%s', escapeshellarg($source->value));
        }

        $this->append($commandLine);
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
