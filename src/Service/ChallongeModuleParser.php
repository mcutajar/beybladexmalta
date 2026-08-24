<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ChallongeFetchException;

/**
 * Reads the two things a Challonge module page carries: the tournament store
 * the bracket UI renders from, and the standings table rendered into the body.
 *
 * `/module` is an embed endpoint rather than a documented API, so everything
 * here fails with what it expected and what it found instead.
 */
class ChallongeModuleParser
{
    /**
     * A two-stage bracket assigns the store twice, once per view, with
     * identical contents. This matches the first.
     */
    private const ASSIGNMENT_PATTERN = '/_initialStoreState\s*\[\s*([\'"])TournamentStore\1\s*\]\s*=\s*/';

    private const SCORECARD_ID = 'scorecard';

    /**
     * @return array<string, mixed>
     */
    public function readStore(string $html): array
    {
        if (1 !== preg_match(self::ASSIGNMENT_PATTERN, $html, $match, PREG_OFFSET_CAPTURE)) {
            throw new ChallongeFetchException("The page carries no _initialStoreState['TournamentStore'] assignment. Challonge may have changed the module page, or this may be a bot check.");
        }

        [$assignment, $offset] = $match[0];

        $json = $this->readObjectAt($html, $offset + strlen($assignment));

        try {
            $store = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ChallongeFetchException(sprintf('The tournament store did not decode as JSON: %s', $exception->getMessage()), previous: $exception);
        }

        if (!is_array($store)) {
            throw new ChallongeFetchException('The tournament store decoded to something other than an object.');
        }

        return $store;
    }

    /**
     * The standings table lives in the page body rather than the store, and
     * only when `show_standings=1` was asked for. A bracket can legitimately
     * have none, so this reports absence rather than failing.
     */
    public function readScorecard(string $html): ?string
    {
        try {
            $document = \Dom\HTMLDocument::createFromString($html, \LIBXML_NOERROR);
        } catch (\ValueError $exception) {
            throw new ChallongeFetchException(sprintf('The page did not parse as HTML: %s', $exception->getMessage()), previous: $exception);
        }

        return $document->getElementById(self::SCORECARD_ID)?->outerHTML;
    }

    /**
     * Walks the JSON object from its opening brace to the brace that closes
     * it. The store is followed by more JavaScript on the same line, so the
     * end of it can only be found by counting — and counting only works if
     * braces inside strings, and escaped quotes inside those strings, are left
     * out of the count.
     */
    private function readObjectAt(string $html, int $start): string
    {
        if (($html[$start] ?? null) !== '{') {
            throw new ChallongeFetchException('The tournament store assignment is not followed by an object.');
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($html);

        for ($offset = $start; $offset < $length; ++$offset) {
            $character = $html[$offset];

            if ($escaped) {
                $escaped = false;

                continue;
            }

            if ($inString) {
                match ($character) {
                    '\\' => $escaped = true,
                    '"' => $inString = false,
                    default => null,
                };

                continue;
            }

            match ($character) {
                '"' => $inString = true,
                '{' => ++$depth,
                '}' => --$depth,
                default => null,
            };

            if (0 === $depth) {
                return substr($html, $start, $offset - $start + 1);
            }
        }

        throw new ChallongeFetchException('The tournament store object is never closed; the page looks truncated.');
    }
}
