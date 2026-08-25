<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeUrl;
use App\Exception\InvalidChallongeSlugException;
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

    /**
     * The slug is checked here rather than trusted, because this is the one
     * place it becomes a path. `ChallongeUrl` guarantees the shape of a slug
     * read from a link, but a reader takes a bare string, and by phase 2 that
     * string plausibly comes from a request — at which point `../../..` is a
     * path outside the directory rather than a bracket that does not exist.
     */
    public function pathFor(string $slug): string
    {
        if (!ChallongeUrl::isSlug($slug)) {
            throw new InvalidChallongeSlugException(sprintf('"%s" is not a bracket slug.', $slug));
        }

        return sprintf('%s/%s.json', $this->directory(), $slug);
    }
}
