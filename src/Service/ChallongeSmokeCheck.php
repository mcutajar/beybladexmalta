<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeSmokeFinding;
use App\Dto\ChallongeSmokeReport;
use App\Exception\ChallongeFetchException;

/**
 * Asks a Challonge module page whether it is still the page this app reads.
 *
 * `/module` is an embed endpoint, not a documented API. It has been stable for
 * years and every Challonge embed on the web depends on it, but Challonge owes
 * nobody notice before changing it. Taking that risk is the right call while
 * the official API is unusable; managing it means finding out at the top of an
 * import rather than at row 40 on a Saturday night.
 *
 * So this is a checklist rather than a parse. It reports every expectation,
 * held or not, because on the day something does change the passes either side
 * of the failure are what say how much of the page is still the page we knew.
 *
 * Two of the expectations are prerequisites — there is no reading a tournament
 * out of a bot check — and everything after them is independent, so one broken
 * field does not hide the next.
 *
 * What it deliberately does not assert is that the bracket has been *played*.
 * A cut that has not started yet, or a match nobody has won, is an ordinary
 * state of a live bracket and not a change in the route. Everything checked
 * here is checked on the stage that orders the event, which is the stage an
 * import cannot do without.
 */
class ChallongeSmokeCheck
{
    private const PAGE = 'an HTML page';

    private const STORE = 'a tournament store that decodes as JSON';

    private const TOURNAMENT = 'a tournament with an id and a format';

    private const ROUNDS = 'at least one round';

    private const MATCHES = 'at least one match';

    private const MATCH_SHAPE = 'matches carrying both players, the scores and a winner';

    private const STANDINGS = 'a standings table for the stage that orders the event';

    /**
     * In the order they are looked for, so a report can list the ones it never
     * reached rather than dropping them.
     */
    private const EXPECTATIONS = [
        self::PAGE,
        self::STORE,
        self::TOURNAMENT,
        self::ROUNDS,
        self::MATCHES,
        self::MATCH_SHAPE,
        self::STANDINGS,
    ];

    private const MATCH_FIELDS = ['player1', 'player2', 'scores', 'winner_id'];

    /**
     * What an interstitial says instead of serving the page. Only ever looked
     * for once the tournament store has already gone missing, so a bracket
     * whose title happens to contain one of these cannot be mistaken for a bot
     * check.
     */
    private const BOT_CHECK_MARKERS = [
        'Verifying you are human',
        'Checking your browser',
        'cf-browser-verification',
        'Attention Required! | Cloudflare',
        'Enable JavaScript and cookies to continue',
        'Just a moment...',
        'g-recaptcha',
        'hcaptcha',
    ];

    /**
     * Enough of the response to recognise it by, when it is not a page at all.
     */
    private const OPENING_CHARACTERS = 60;

    public function __construct(
        private ChallongeModuleParser $parser,
        private ChallongeStandingsParser $standingsParser,
    ) {
    }

    /**
     * @param string $source where the page came from, for the message an abort is made of
     */
    public function check(string $html, string $source): ChallongeSmokeReport
    {
        $page = $this->page($html);

        if ($page->isFailure()) {
            return ChallongeSmokeReport::stoppedAfter($source, [$page], self::EXPECTATIONS);
        }

        try {
            $store = $this->parser->readStore($html);
        } catch (ChallongeFetchException $exception) {
            return ChallongeSmokeReport::stoppedAfter($source, [
                $page,
                ChallongeSmokeFinding::failed(self::STORE, $this->whyTheStoreIsMissing($html, $exception)),
            ], self::EXPECTATIONS);
        }

        return new ChallongeSmokeReport($source, [
            $page,
            ChallongeSmokeFinding::passed(self::STORE, sprintf('a store carrying %s.', implode(', ', array_keys($store)))),
            $this->tournament($store),
            $this->rounds($store),
            $this->matches($store),
            $this->matchShape($store),
            $this->standings($store, $html),
        ]);
    }

    private function page(string $html): ChallongeSmokeFinding
    {
        if ('' === trim($html)) {
            return ChallongeSmokeFinding::failed(self::PAGE, 'an empty response.');
        }

        if (1 !== preg_match('/<html[\s>]/i', $html)) {
            return ChallongeSmokeFinding::failed(self::PAGE, sprintf(
                '%d bytes that are not a document; they open "%s".',
                strlen($html),
                $this->opening($html),
            ));
        }

        return ChallongeSmokeFinding::passed(self::PAGE, sprintf('%d KB of HTML.', (int) ceil(strlen($html) / 1024)));
    }

    private function whyTheStoreIsMissing(string $html, ChallongeFetchException $exception): string
    {
        foreach (self::BOT_CHECK_MARKERS as $marker) {
            if (false !== stripos($html, $marker)) {
                return sprintf('a page that says "%s", which is a bot check standing in for the bracket.', $marker);
            }
        }

        return lcfirst($exception->getMessage());
    }

    /**
     * @param array<string, mixed> $store
     */
    private function tournament(array $store): ChallongeSmokeFinding
    {
        $tournament = $store['tournament'] ?? null;

        if (!is_array($tournament)) {
            return ChallongeSmokeFinding::failed(self::TOURNAMENT, sprintf('a store whose "tournament" is %s.', get_debug_type($tournament)));
        }

        $id = $tournament['id'] ?? null;

        if (!is_int($id)) {
            return ChallongeSmokeFinding::failed(self::TOURNAMENT, sprintf('a tournament whose "id" is %s.', get_debug_type($id)));
        }

        $type = $tournament['tournament_type'] ?? null;

        if (!is_string($type) || '' === $type) {
            return ChallongeSmokeFinding::failed(self::TOURNAMENT, sprintf(
                'a tournament whose "tournament_type" is %s.',
                '' === $type ? 'empty' : get_debug_type($type),
            ));
        }

        $state = $tournament['state'] ?? null;

        return ChallongeSmokeFinding::passed(self::TOURNAMENT, sprintf(
            'tournament %d, a %s bracket, %s.',
            $id,
            $type,
            is_string($state) && '' !== $state ? $state : 'in an unstated state',
        ));
    }

    /**
     * @param array<string, mixed> $store
     */
    private function rounds(array $store): ChallongeSmokeFinding
    {
        [$label, $collection] = $this->rankingStage($store);

        $rounds = $collection['rounds'] ?? null;

        if (!is_array($rounds)) {
            return ChallongeSmokeFinding::failed(self::ROUNDS, sprintf('%s, whose "rounds" is %s.', $label, get_debug_type($rounds)));
        }

        if ([] === $rounds) {
            return ChallongeSmokeFinding::failed(self::ROUNDS, sprintf('%s, whose "rounds" is empty.', $label));
        }

        return ChallongeSmokeFinding::passed(self::ROUNDS, sprintf('%d in %s.', count($rounds), $label));
    }

    /**
     * @param array<string, mixed> $store
     */
    private function matches(array $store): ChallongeSmokeFinding
    {
        [$label, $collection] = $this->rankingStage($store);

        $byRound = $collection['matches_by_round'] ?? null;

        if (!is_array($byRound)) {
            return ChallongeSmokeFinding::failed(self::MATCHES, sprintf('%s, whose "matches_by_round" is %s.', $label, get_debug_type($byRound)));
        }

        $played = 0;

        foreach ($this->onlyArrays($byRound) as $round) {
            $played += count($this->onlyArrays($round));
        }

        if (0 === $played) {
            return ChallongeSmokeFinding::failed(self::MATCHES, sprintf('%s, whose "matches_by_round" holds no matches.', $label));
        }

        return ChallongeSmokeFinding::passed(self::MATCHES, sprintf('%d across %d rounds of %s.', $played, count($byRound), $label));
    }

    /**
     * The four fields an import reads out of a match. They are checked for
     * presence rather than for content: Challonge writes null into every one
     * of them at some point in an ordinary bracket — a slot nobody has reached
     * yet, a match nobody has won — and a rename is what this is looking for.
     *
     * @param array<string, mixed> $store
     */
    private function matchShape(array $store): ChallongeSmokeFinding
    {
        $matches = $this->everyMatch($store);

        if ([] === $matches) {
            return ChallongeSmokeFinding::failed(self::MATCH_SHAPE, 'a store with no match anywhere in it to read.');
        }

        $decided = 0;

        foreach ($matches as $match) {
            $id = is_int($match['id'] ?? null) ? sprintf('match %d', $match['id']) : 'a match with no id';

            foreach (self::MATCH_FIELDS as $field) {
                if (!array_key_exists($field, $match)) {
                    return ChallongeSmokeFinding::failed(self::MATCH_SHAPE, sprintf(
                        '%s carrying no "%s" field; it holds %s.',
                        $id,
                        $field,
                        implode(', ', array_keys($match)),
                    ));
                }
            }

            $winner = $match['winner_id'];
            $scores = $match['scores'];

            if (null !== $winner && !is_int($winner)) {
                return ChallongeSmokeFinding::failed(self::MATCH_SHAPE, sprintf('%s, whose "winner_id" is %s.', $id, get_debug_type($winner)));
            }

            if (null !== $scores && !is_array($scores)) {
                return ChallongeSmokeFinding::failed(self::MATCH_SHAPE, sprintf('%s, whose "scores" is %s.', $id, get_debug_type($scores)));
            }

            if (is_int($winner) && is_array($scores) && [] !== $scores) {
                ++$decided;
            }
        }

        return ChallongeSmokeFinding::passed(self::MATCH_SHAPE, sprintf(
            '%d matches, %d of them carrying a winner and a scoreline.',
            count($matches),
            $decided,
        ));
    }

    /**
     * @param array<string, mixed> $store
     */
    private function standings(array $store, string $html): ChallongeSmokeFinding
    {
        [$label, $collection, $isGroup] = $this->rankingStage($store);

        /*
         * A group renders its standings into the store; a stage at the top
         * level renders them into the page body instead. Which of the two to
         * look in is the whole of "for the shape the bracket claims to be".
         */
        try {
            $scorecard = $isGroup
                ? ($collection['scorecard_html'] ?? null)
                : $this->parser->readScorecard($html);

            if (!is_string($scorecard) || '' === trim($scorecard)) {
                return ChallongeSmokeFinding::failed(self::STANDINGS, sprintf(
                    '%s with no standings table. Challonge renders one only when show_standings=1 is sent, and a bracket that renders none even then cannot be ranked.',
                    $label,
                ));
            }

            $rows = $this->standingsParser->parse($scorecard);
        } catch (ChallongeFetchException $exception) {
            return ChallongeSmokeFinding::failed(self::STANDINGS, lcfirst($exception->getMessage()));
        }

        if ([] === $rows) {
            return ChallongeSmokeFinding::failed(self::STANDINGS, sprintf(
                'a standings table for %s that parses to no rows at all; its columns have changed.',
                $label,
            ));
        }

        return ChallongeSmokeFinding::passed(self::STANDINGS, sprintf('%d rows for %s.', count($rows), $label));
    }

    /**
     * The stage whose standings order the event.
     *
     * That is the group stage when the bracket has a cut and the whole bracket
     * when it does not — the same stage ChallongeSnapshot::rankingStage()
     * answers with, and the one an import reads a finishing order from. The
     * cut is left out on purpose: a cut that has not been played yet is a
     * bracket mid-event, not a route that has changed.
     *
     * @param array<string, mixed> $store
     *
     * @return array{string, array<string, mixed>, bool}
     */
    private function rankingStage(array $store): array
    {
        return $this->collections($store)[0];
    }

    /**
     * Every part of the store that holds matches of its own: each group in
     * turn, then the top level — which is the cut when there are groups and
     * the whole bracket when there are not.
     *
     * @param array<string, mixed> $store
     *
     * @return non-empty-list<array{string, array<string, mixed>, bool}>
     */
    private function collections(array $store): array
    {
        $collections = [];

        foreach ($this->onlyArrays($store['groups'] ?? null) as $position => $group) {
            $name = $group['name'] ?? null;

            $collections[] = [
                is_string($name) && '' !== $name ? sprintf('the group stage "%s"', $name) : sprintf('group stage %d', $position + 1),
                $group,
                true,
            ];
        }

        $collections[] = [[] === $collections ? 'the bracket' : 'the final stage', $store, false];

        return $collections;
    }

    /**
     * @param array<string, mixed> $store
     *
     * @return list<array<string, mixed>>
     */
    private function everyMatch(array $store): array
    {
        $matches = [];

        foreach ($this->collections($store) as [, $collection]) {
            foreach ($this->onlyArrays($collection['matches_by_round'] ?? null) as $round) {
                foreach ($this->onlyArrays($round) as $match) {
                    $matches[] = $match;
                }
            }

            foreach ($this->onlyArrays($collection['consolation_matches'] ?? null) as $match) {
                $matches[] = $match;
            }

            $thirdPlace = $collection['third_place_match'] ?? null;

            if (is_array($thirdPlace)) {
                $matches[] = $thirdPlace;
            }
        }

        return $matches;
    }

    /**
     * Whatever of a value is a list of objects, and nothing where it is not.
     *
     * The reader in the pipeline refuses a field of the wrong type outright;
     * this one is a report and so has to keep going, and the expectation the
     * missing entries belong to says what went.
     *
     * @return list<array<string, mixed>>
     */
    private function onlyArrays(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $arrays = [];

        foreach ($value as $item) {
            if (is_array($item)) {
                $arrays[] = $item;
            }
        }

        return $arrays;
    }

    private function opening(string $html): string
    {
        $opening = trim((string) preg_replace('/\s+/', ' ', substr($html, 0, self::OPENING_CHARACTERS)));

        return strlen($html) > self::OPENING_CHARACTERS ? $opening.'…' : $opening;
    }
}
