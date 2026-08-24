<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Where a captured bracket lives.
 *
 * One fact — `var/data/challonge/<slug>.json` — held in one place, because
 * both ends of the pipeline need it: the fetch writes there and everything
 * downstream reads from there. A second copy of the path is a second chance
 * for the two to disagree.
 */
class ChallongeSnapshotFiles
{
    private const DIRECTORY = 'var/data/challonge';

    public function __construct(
        private KernelInterface $kernel,
    ) {
    }

    public function directory(): string
    {
        return $this->kernel->getProjectDir().'/'.self::DIRECTORY;
    }

    public function pathFor(string $slug): string
    {
        return sprintf('%s/%s.json', $this->directory(), $slug);
    }
}
