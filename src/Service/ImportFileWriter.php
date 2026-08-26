<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TeamPlacement;
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
        $contents = '';

        foreach ($placements as $placement) {
            $contents .= 0 === $placement->bonusPoints
                ? sprintf("%s\n", $placement->playerName)
                : sprintf("%s,%d\n", $placement->playerName, $placement->bonusPoints);
        }

        return $this->put($title, $heldOn, $contents);
    }

    /**
     * The same thing for a team event, in the shape `TeamListParser` reads.
     *
     * An unclaimed team keeps its colon with nothing after it, because the
     * file is read by people as well as by the parser and a bare name would
     * leave "nobody knows who was in this" indistinguishable from "somebody
     * forgot to finish the line".
     *
     * @param list<TeamPlacement> $teams
     *
     * @return string the absolute path of the generated roster file
     */
    public function writeTeams(
        string $title,
        \DateTimeImmutable $heldOn,
        array $teams,
    ): string {
        $contents = '';

        foreach ($teams as $team) {
            $contents .= rtrim(sprintf(
                '%s: %s',
                $team->teamName,
                implode(' + ', $team->memberNames),
            ))."\n";
        }

        return $this->put($title, $heldOn, $contents);
    }

    private function put(
        string $title,
        \DateTimeImmutable $heldOn,
        string $contents,
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
