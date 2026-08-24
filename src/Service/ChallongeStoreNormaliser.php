<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeRound;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeUrl;
use App\Exception\ChallongeFetchException;

/**
 * Flattens Challonge's tournament store into the snapshot we keep.
 *
 * Challonge nests a bracket three different ways. A Swiss event with a top cut
 * puts the Swiss rounds in `groups[0]` and the cut at the top level; a
 * Swiss-only or round-robin event puts everything at the top level and has no
 * groups at all. Here all three become a list of stages in the order they were
 * played, and everything that is only there for the embed — portrait URLs,
 * checksums, chat and station flags, and the fields that are null in every
 * match of every bracket — is left behind.
 */
class ChallongeStoreNormaliser
{
    public function __construct(
        private ChallongeStandingsParser $standingsParser,
    ) {
    }

    /**
     * @param array<string, mixed> $store             the decoded `TournamentStore` object
     * @param ?string              $bodyScorecardHtml the standings table rendered into the page body, which
     *                                                is the final stage's when the bracket has two
     */
    public function normalise(
        array $store,
        ?string $bodyScorecardHtml,
        ChallongeUrl $url,
        \DateTimeImmutable $fetchedAt,
    ): ChallongeSnapshot {
        $tournament = $this->arrayAt($store, 'tournament');

        $id = $this->intAt($tournament, 'id');
        $type = $this->nonEmptyStringAt($tournament, 'tournament_type');

        if (null === $id || null === $type) {
            throw new ChallongeFetchException('The tournament store carries no tournament id or type.');
        }

        $groups = $this->arrayListAt($store, 'groups');

        $stages = [];

        foreach ($groups as $group) {
            $stages[] = $this->stage(
                collection: $group,
                kind: ChallongeStageKind::Group,
                fallbackFormat: $type,
                scorecardHtml: $this->nonEmptyStringAt($group, 'scorecard_html'),
            );
        }

        $stages[] = $this->stage(
            collection: $store,
            kind: [] === $groups ? ChallongeStageKind::Single : ChallongeStageKind::Final,
            fallbackFormat: $type,
            scorecardHtml: $bodyScorecardHtml,
        );

        return new ChallongeSnapshot(
            slug: $url->slug,
            sourceUrl: $url->moduleUrl(),
            fetchedAt: $fetchedAt,
            tournamentId: $id,
            tournamentType: $type,
            tournamentState: $this->nonEmptyStringAt($tournament, 'state') ?? 'unknown',
            isTeamTournament: true === ($tournament['is_team'] ?? null),
            stages: $stages,
        );
    }

    /**
     * @param array<string, mixed> $collection either the store itself or one of its groups
     */
    private function stage(
        array $collection,
        ChallongeStageKind $kind,
        string $fallbackFormat,
        ?string $scorecardHtml,
    ): ChallongeStage {
        $rawMatches = $this->rawMatches($collection);

        return new ChallongeStage(
            kind: $kind,
            name: $this->nonEmptyStringAt($collection, 'name'),
            format: $this->nonEmptyStringAt($this->arrayAt($collection, 'tournament'), 'tournament_type') ?? $fallbackFormat,
            rounds: $this->rounds($collection),
            participants: $this->participants($rawMatches),
            matches: $this->matches($rawMatches),
            standings: $this->standingsParser->parse($scorecardHtml),
        );
    }

    /**
     * @param array<string, mixed> $collection
     *
     * @return list<ChallongeRound>
     */
    private function rounds(array $collection): array
    {
        $rounds = [];

        foreach ($this->arrayListAt($collection, 'rounds') as $round) {
            $number = $this->intAt($round, 'number');

            if (null !== $number) {
                $rounds[] = new ChallongeRound($number, $this->nonEmptyStringAt($round, 'title'));
            }
        }

        return $rounds;
    }

    /**
     * Every match the stage holds, in the order it was played.
     *
     * The third-place playoff is not in `matches_by_round` — it hangs off the
     * store on its own, and would simply go missing if it were not picked up
     * here. It is flagged rather than merged in silently, because "the last
     * match of the final stage" is how the knockout winner is identified and
     * the playoff is played after it.
     *
     * @param array<string, mixed> $collection
     *
     * @return list<array{array<string, mixed>, bool}> each raw match, with whether it is a consolation match
     */
    private function rawMatches(array $collection): array
    {
        $matches = [];
        $seen = [];

        $byRound = $this->arrayAt($collection, 'matches_by_round');
        ksort($byRound, \SORT_NUMERIC);

        foreach ($byRound as $round) {
            foreach ($this->arrayListIn($round, 'matches_by_round') as $match) {
                $matches[] = [$match, false];

                $id = $this->intAt($match, 'id');

                if (null !== $id) {
                    $seen[$id] = true;
                }
            }
        }

        $consolation = $this->arrayListAt($collection, 'consolation_matches');

        $thirdPlace = $collection['third_place_match'] ?? null;

        if (is_array($thirdPlace)) {
            $consolation[] = $thirdPlace;
        }

        foreach ($consolation as $match) {
            $id = $this->intAt($match, 'id');

            if (null === $id || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $matches[] = [$match, true];
        }

        return $matches;
    }

    /**
     * @param list<array{array<string, mixed>, bool}> $rawMatches
     *
     * @return list<ChallongeMatch>
     */
    private function matches(array $rawMatches): array
    {
        return array_map(
            fn (array $raw): ChallongeMatch => $this->match($raw[0], consolation: $raw[1]),
            $rawMatches,
        );
    }

    /**
     * @param array<string, mixed> $match
     */
    private function match(array $match, bool $consolation): ChallongeMatch
    {
        $id = $this->intAt($match, 'id');

        if (null === $id) {
            throw new ChallongeFetchException('A match in the tournament store carries no id.');
        }

        return new ChallongeMatch(
            id: $id,
            round: $this->intAt($match, 'round') ?? 0,
            identifier: $this->nonEmptyStringAt($match, 'raw_identifier'),
            state: $this->nonEmptyStringAt($match, 'state') ?? 'unknown',
            player1Id: $this->intAt($this->arrayAt($match, 'player1'), 'id'),
            player2Id: $this->intAt($this->arrayAt($match, 'player2'), 'id'),
            games: $this->games($match),
            score: $this->integersIn($this->arrayAt($match, 'scores'), 'scores'),
            winnerId: $this->intAt($match, 'winner_id'),
            loserId: $this->intAt($match, 'loser_id'),
            forfeited: true === ($match['forfeited'] ?? null),
            consolation: $consolation,
        );
    }

    /**
     * @param array<string, mixed> $match
     *
     * @return list<list<int>>
     */
    private function games(array $match): array
    {
        $games = [];

        foreach ($this->arrayListAt($match, 'games') as $game) {
            $games[] = $this->integersIn($game, 'games');
        }

        return $games;
    }

    /**
     * Every entrant this stage saw, in seeded order. They are collected from
     * the matches because the store lists them nowhere else, and they are kept
     * per stage because the group and final stages use disjoint id spaces.
     *
     * @param list<array{array<string, mixed>, bool}> $rawMatches
     *
     * @return list<ChallongeParticipant>
     */
    private function participants(array $rawMatches): array
    {
        $participants = [];

        foreach ($rawMatches as [$match, $consolation]) {
            foreach (['player1', 'player2'] as $side) {
                $player = $this->arrayAt($match, $side);

                $id = $this->intAt($player, 'id');
                $name = $this->nonEmptyStringAt($player, 'display_name');

                if (null === $id || null === $name || isset($participants[$id])) {
                    continue;
                }

                $participants[$id] = new ChallongeParticipant(
                    id: $id,
                    participantId: $this->intAt($player, 'participant_id'),
                    seed: $this->intAt($player, 'seed'),
                    name: $name,
                );
            }
        }

        $participants = array_values($participants);

        usort(
            $participants,
            static fn (ChallongeParticipant $a, ChallongeParticipant $b): int => ($a->seed ?? \PHP_INT_MAX) <=> ($b->seed ?? \PHP_INT_MAX),
        );

        return $participants;
    }

    /**
     * Nothing in the store is guaranteed, so every read out of it goes through
     * one of these.
     *
     * A field that is absent or null is ordinary: Challonge writes null for the
     * playoff a bracket never had, for a match nobody has won yet, for an
     * entrant with no linked account. Those answer with null.
     *
     * A field that is *present and the wrong type* is not ordinary — it means
     * the payload has changed shape, and a reader that shrugged and carried on
     * with null would write a snapshot quietly missing a column and say nothing.
     * Those refuse, naming the field and what came back.
     *
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function arrayAt(array $source, string $key): array
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
    private function arrayListAt(array $source, string $key): array
    {
        return $this->arrayListIn($source[$key] ?? null, $key);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arrayListIn(mixed $value, string $key): array
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
    private function integersIn(array $values, string $key): array
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
     */
    private function intAt(array $source, string $key): ?int
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
     * An empty string is Challonge saying it has nothing, which is the same
     * thing as the field being absent — a group with no standings renders an
     * empty `scorecard_html` rather than dropping it.
     *
     * @param array<string, mixed> $source
     */
    private function nonEmptyStringAt(array $source, string $key): ?string
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

    private function wrongType(string $key, string $expected, mixed $value): ChallongeFetchException
    {
        return new ChallongeFetchException(sprintf(
            'The Challonge field "%s" holds %s where %s was expected. The module payload has changed shape.',
            $key,
            get_debug_type($value),
            $expected,
        ));
    }
}
