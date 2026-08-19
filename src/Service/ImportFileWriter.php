<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TournamentPlacement;
use App\Exception\ImportFileWriteException;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Materialises a placement list on disk so the ledger replay command has a
 * source file to point at.
 */
class ImportFileWriter
{
    public function __construct(
        private KernelInterface $kernel,
    ) {
    }

    /**
     * @param list<TournamentPlacement> $placements
     *
     * @return string the absolute path of the generated import file
     */
    public function write(
        string $title,
        \DateTimeImmutable $heldOn,
        array $placements,
    ): string {
        $directory = $this->kernel->getProjectDir().'/var/data/imports';

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ImportFileWriteException(sprintf('Failed to create the import directory "%s".', $directory));
        }

        $filePath = sprintf(
            '%s/%s-%s.txt',
            $directory,
            $heldOn->format('Y-m-d'),
            $this->slugify($title),
        );

        $contents = '';

        foreach ($placements as $placement) {
            $contents .= 0 === $placement->bonusPoints
                ? sprintf("%s\n", $placement->playerName)
                : sprintf("%s,%d\n", $placement->playerName, $placement->bonusPoints);
        }

        $written = @file_put_contents($filePath, $contents, LOCK_EX);

        if (false === $written) {
            throw new ImportFileWriteException(sprintf('Failed to write the import file "%s".', $filePath));
        }

        return $filePath;
    }

    private function slugify(string $title): string
    {
        return (string) preg_replace(
            '/[^a-z0-9_-]/',
            '',
            strtolower(str_replace(' ', '-', $title)),
        );
    }
}
