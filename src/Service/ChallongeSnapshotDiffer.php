<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeSnapshot;
use App\Dto\SnapshotDifference;

/**
 * Says what a bracket has changed since it was captured.
 *
 * A snapshot is the record: everything downstream reads it, and a bracket
 * edited or deleted upstream cannot change what we already know. That is the
 * whole point of the file, and it is also the risk — an evening corrected on
 * Challonge a week after the import is a correction we never hear about.
 *
 * "Just re-fetch it and look at `git diff`" is not the tool, for one specific
 * reason: `fetched_at` is rewritten on every fetch, so re-fetching an
 * unchanged bracket produces exactly one line of diff and looks like a change.
 * Everything else in a snapshot is byte-stable — re-fetching `nppk0890`
 * changed that line and nothing else — so this compares everything except it,
 * and an unchanged bracket comes back with nothing to say.
 *
 * A subtree that exists on one side only is reported once, at the point it
 * appears, rather than descended into. A stage the bracket has gained is one
 * line, not four hundred.
 */
class ChallongeSnapshotDiffer
{
    /**
     * The one field a fetch always rewrites, and the reason this class exists.
     */
    private const string FETCHED_AT = 'fetched_at';

    /**
     * How much of a value to print before it stops being readable.
     */
    private const int LEGIBLE = 120;

    /**
     * Stands for a key one side does not have at all, which is not the same
     * as a key whose value is null — `identifier` is null on a match Challonge
     * never labelled, and a bracket that has since labelled it changed a value
     * rather than gained a field.
     */
    private const string ABSENT = "\0absent";

    /**
     * @return list<SnapshotDifference> empty when the bracket is what we captured
     */
    public function compare(ChallongeSnapshot $stored, ChallongeSnapshot $fetched): array
    {
        $differences = [];

        $this->walk('', $this->comparable($stored), $this->comparable($fetched), $differences);

        return $differences;
    }

    /**
     * @return array<string, mixed>
     */
    private function comparable(ChallongeSnapshot $snapshot): array
    {
        $fields = $snapshot->toArray();

        unset($fields[self::FETCHED_AT]);

        return $fields;
    }

    /**
     * @param list<SnapshotDifference> $differences
     */
    private function walk(string $path, mixed $stored, mixed $fetched, array &$differences): void
    {
        if ($stored === $fetched) {
            return;
        }

        if (!is_array($stored) || !is_array($fetched)) {
            $differences[] = new SnapshotDifference($this->name($path), $this->render($stored), $this->render($fetched));

            return;
        }

        foreach ($this->keys($stored, $fetched) as $key) {
            $this->walk(
                $this->step($path, $key),
                \array_key_exists($key, $stored) ? $stored[$key] : self::ABSENT,
                \array_key_exists($key, $fetched) ? $fetched[$key] : self::ABSENT,
                $differences,
            );
        }
    }

    /**
     * Every key either side has, the captured order first, so a difference is
     * reported where a reader would look for it.
     *
     * @param array<array-key, mixed> $stored
     * @param array<array-key, mixed> $fetched
     *
     * @return list<array-key>
     */
    private function keys(array $stored, array $fetched): array
    {
        $keys = array_keys($stored);

        foreach (array_keys($fetched) as $key) {
            if (!\array_key_exists($key, $stored)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    private function step(string $path, string|int $key): string
    {
        return is_int($key)
            ? sprintf('%s[%d]', $path, $key)
            : ltrim(sprintf('%s.%s', $path, $key), '.');
    }

    private function name(string $path): string
    {
        return '' === $path ? 'the snapshot' : $path;
    }

    /**
     * A value as a person would read it in a message, or null where that side
     * of the file has no such key.
     */
    private function render(mixed $value): ?string
    {
        if (self::ABSENT === $value) {
            return null;
        }

        $rendered = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        if (false === $rendered) {
            return get_debug_type($value);
        }

        return mb_strlen($rendered) > self::LEGIBLE
            ? mb_substr($rendered, 0, self::LEGIBLE).'…'
            : $rendered;
    }
}
