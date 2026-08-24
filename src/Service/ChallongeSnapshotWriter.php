<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeSnapshot;
use App\Exception\ChallongeSnapshotWriteException;

/**
 * Materialises a fetched bracket on disk, the way ImportFileWriter does for a
 * placement list.
 *
 * `var/data/challonge/` is tracked by git for the same reason
 * `var/data/imports/` is: the file is the recovery artifact. A bracket that is
 * edited or deleted upstream must not be able to change what we already know.
 */
class ChallongeSnapshotWriter
{
    /**
     * Pretty-printed and unescaped so that a tracked snapshot produces a
     * diff a person can read.
     */
    private const JSON_FLAGS = \JSON_THROW_ON_ERROR
        | \JSON_PRETTY_PRINT
        | \JSON_UNESCAPED_SLASHES
        | \JSON_UNESCAPED_UNICODE;

    public function __construct(
        private ChallongeSnapshotFiles $files,
    ) {
    }

    public function pathFor(string $slug): string
    {
        return $this->files->pathFor($slug);
    }

    /**
     * @return string the absolute path of the snapshot
     */
    public function write(ChallongeSnapshot $snapshot): string
    {
        $directory = $this->files->directory();

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new ChallongeSnapshotWriteException(sprintf('Failed to create the snapshot directory "%s".', $directory));
        }

        try {
            $json = json_encode($snapshot->toArray(), self::JSON_FLAGS)."\n";
        } catch (\JsonException $exception) {
            throw new ChallongeSnapshotWriteException(sprintf('The snapshot for "%s" could not be encoded as JSON: %s', $snapshot->slug, $exception->getMessage()), previous: $exception);
        }

        $filePath = $this->pathFor($snapshot->slug);

        /*
         * Written beside the target and moved into place, so that a failure
         * part-way through leaves the previous snapshot intact and never
         * leaves half a snapshot behind under the real name.
         */
        $temporaryPath = $filePath.'.part';

        if (false === @file_put_contents($temporaryPath, $json, LOCK_EX)) {
            @unlink($temporaryPath);

            throw new ChallongeSnapshotWriteException(sprintf('Failed to write the snapshot file "%s".', $filePath));
        }

        if (!@rename($temporaryPath, $filePath)) {
            @unlink($temporaryPath);

            throw new ChallongeSnapshotWriteException(sprintf('Failed to move the snapshot into place at "%s".', $filePath));
        }

        return $filePath;
    }
}
