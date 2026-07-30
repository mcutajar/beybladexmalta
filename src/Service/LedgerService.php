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
        $logFilePath = $this->kernel->getProjectDir()
            .'/var/log/command_ledger.sh';

        $commandLine = sprintf(
            "php bin/console app:register-payment %s %s\n",
            escapeshellarg($seasonSlug),
            escapeshellarg($playerName),
        );

        $written = @file_put_contents(
            $logFilePath,
            $commandLine,
            FILE_APPEND | LOCK_EX,
        );

        if (false === $written) {
            throw new LedgerWriteException('Failed to write to ledger file.');
        }
    }
}
