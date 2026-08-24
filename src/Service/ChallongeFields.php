<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Reads one field out of decoded JSON, and refuses anything unexpected.
 *
 * Both directions of the Challonge pipeline face the same problem: a decoded
 * payload where nothing is guaranteed. A field that is absent or null is
 * ordinary — Challonge writes null for the playoff a bracket never had, for a
 * match nobody has won yet, for an entrant with no linked account — and those
 * answer with null. A field that is *present and the wrong type* is not
 * ordinary. It means the payload has changed shape, and a reader that shrugged
 * and carried on with null would produce a snapshot quietly missing a column
 * and say nothing.
 *
 * What is being read differs at each end, so the caller supplies both the noun
 * that names a field and the exception to raise. The message says which field,
 * what came back, and what was expected.
 */
final class ChallongeFields
{
    /**
     * @param string                       $subject what one field is called, e.g. "Challonge field"
     * @param \Closure(string): \Throwable $refuse  turns the problem into the caller's own exception
     */
    public function __construct(
        private readonly string $subject,
        private readonly \Closure $refuse,
    ) {
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    public function arrayAt(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        if (null === $value) {
            return [];
        }

        if (!is_array($value)) {
            throw $this->wrongType($key, 'an object', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<array<string, mixed>>
     */
    public function arrayListAt(array $source, string $key): array
    {
        return $this->arrayListIn($source[$key] ?? null, $key);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function arrayListIn(mixed $value, string $key): array
    {
        if (null === $value) {
            return [];
        }

        if (!is_array($value)) {
            throw $this->wrongType($key, 'a list', $value);
        }

        $list = [];

        foreach ($value as $item) {
            if (!is_array($item)) {
                throw $this->wrongType($key, 'a list of objects', $item);
            }

            $list[] = $item;
        }

        return $list;
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return list<int>
     */
    public function integersIn(array $values, string $key): array
    {
        $integers = [];

        foreach ($values as $value) {
            if (!is_int($value)) {
                throw $this->wrongType($key, 'a list of whole numbers', $value);
            }

            $integers[] = $value;
        }

        return $integers;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<int>
     */
    public function integerListAt(array $source, string $key): array
    {
        return $this->integersIn($this->arrayAt($source, $key), $key);
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return list<string>
     */
    public function stringListAt(array $source, string $key): array
    {
        $strings = [];

        foreach ($this->arrayAt($source, $key) as $value) {
            if (!is_string($value)) {
                throw $this->wrongType($key, 'a list of text', $value);
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * A table of cells kept under the header label Challonge printed above
     * them, which JSON gives back as an object and PHP as an array whose keys
     * are whatever those labels were.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, string>
     */
    public function stringMapAt(array $source, string $key): array
    {
        $map = [];

        foreach ($this->arrayAt($source, $key) as $label => $value) {
            if (!is_string($value)) {
                throw $this->wrongType($key, 'an object of text', $value);
            }

            $map[(string) $label] = $value;
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $source
     */
    public function intAt(array $source, string $key): ?int
    {
        $value = $source[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_int($value)) {
            throw $this->wrongType($key, 'a whole number', $value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    public function requiredIntAt(array $source, string $key): int
    {
        return $this->intAt($source, $key) ?? throw $this->missing($key, 'a whole number');
    }

    /**
     * An empty string is Challonge saying it has nothing, which is the same
     * thing as the field being absent — a group with no standings renders an
     * empty `scorecard_html` rather than dropping it.
     *
     * @param array<string, mixed> $source
     */
    public function nonEmptyStringAt(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;

        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw $this->wrongType($key, 'text', $value);
        }

        return '' === $value ? null : $value;
    }

    /**
     * @param array<string, mixed> $source
     */
    public function requiredStringAt(array $source, string $key): string
    {
        return $this->nonEmptyStringAt($source, $key) ?? throw $this->missing($key, 'text');
    }

    /**
     * @param array<string, mixed> $source
     */
    public function boolAt(array $source, string $key): bool
    {
        $value = $source[$key] ?? null;

        if (null === $value) {
            return false;
        }

        if (!is_bool($value)) {
            throw $this->wrongType($key, 'true or false', $value);
        }

        return $value;
    }

    public function missing(string $key, string $expected): \Throwable
    {
        return ($this->refuse)(sprintf(
            'The %s "%s" is missing, where %s was expected.',
            $this->subject,
            $key,
            $expected,
        ));
    }

    public function refuse(string $problem): \Throwable
    {
        return ($this->refuse)($problem);
    }

    private function wrongType(string $key, string $expected, mixed $value): \Throwable
    {
        return ($this->refuse)(sprintf(
            'The %s "%s" holds %s where %s was expected.',
            $this->subject,
            $key,
            get_debug_type($value),
            $expected,
        ));
    }
}
