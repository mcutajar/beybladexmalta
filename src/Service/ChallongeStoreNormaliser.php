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
    private readonly ChallongeFields $fields;

    public function __construct(
        private ChallongeStandingsParser $standingsParser,
    ) {
        /*
         * Nothing in the store is guaranteed, so every read out of it goes
         * through this. What counts as ordinary and what counts as the payload
         * having changed shape is spelled out on ChallongeFields.
         */
        $this->fields = new ChallongeFields(
            'Challonge field',
            static fn (string $problem): \Throwable => new ChallongeFetchException($problem.' The module payload has changed shape.'),
        );
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
        $tournament = $this->fields->arrayAt($store, 'tournament');

        $id = $this->fields->intAt($tournament, 'id');
        $type = $this->fields->nonEmptyStringAt($tournament, 'tournament_type');

        if (null === $id || null === $type) {
            throw new ChallongeFetchException('The tournament store carries no tournament id or type.');
        }

        $groups = $this->fields->arrayListAt($store, 'groups');

        $stages = [];

        foreach ($groups as $group) {
            $stages[] = $this->stage(
                collection: $group,
                kind: ChallongeStageKind::Group,
                fallbackFormat: $type,
                scorecardHtml: $this->fields->nonEmptyStringAt($group, 'scorecard_html'),
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
            tournamentState: $this->fields->nonEmptyStringAt($tournament, 'state') ?? 'unknown',
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
            name: $this->fields->nonEmptyStringAt($collection, 'name'),
            format: $this->fields->nonEmptyStringAt($this->fields->arrayAt($collection, 'tournament'), 'tournament_type') ?? $fallbackFormat,
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

        foreach ($this->fields->arrayListAt($collection, 'rounds') as $round) {
            $number = $this->fields->intAt($round, 'number');

            if (null !== $number) {
                $rounds[] = new ChallongeRound($number, $this->fields->nonEmptyStringAt($round, 'title'));
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

        $byRound = $this->fields->arrayAt($collection, 'matches_by_round');
        ksort($byRound, \SORT_NUMERIC);

        foreach ($byRound as $round) {
            foreach ($this->fields->arrayListIn($round, 'matches_by_round') as $match) {
                $matches[] = [$match, false];

                $id = $this->fields->intAt($match, 'id');

                if (null !== $id) {
                    $seen[$id] = true;
                }
            }
        }

        $consolation = $this->fields->arrayListAt($collection, 'consolation_matches');

        $thirdPlace = $collection['third_place_match'] ?? null;

        if (is_array($thirdPlace)) {
            $consolation[] = $thirdPlace;
        }

        foreach ($consolation as $match) {
            $id = $this->fields->intAt($match, 'id');

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
        $id = $this->fields->intAt($match, 'id');

        if (null === $id) {
            throw new ChallongeFetchException('A match in the tournament store carries no id.');
        }

        return new ChallongeMatch(
            id: $id,
            round: $this->fields->intAt($match, 'round') ?? 0,
            identifier: $this->fields->nonEmptyStringAt($match, 'raw_identifier'),
            state: $this->fields->nonEmptyStringAt($match, 'state') ?? 'unknown',
            player1Id: $this->fields->intAt($this->fields->arrayAt($match, 'player1'), 'id'),
            player2Id: $this->fields->intAt($this->fields->arrayAt($match, 'player2'), 'id'),
            games: $this->games($match),
            score: $this->fields->integersIn($this->fields->arrayAt($match, 'scores'), 'scores'),
            winnerId: $this->fields->intAt($match, 'winner_id'),
            loserId: $this->fields->intAt($match, 'loser_id'),
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

        foreach ($this->fields->arrayListAt($match, 'games') as $game) {
            $games[] = $this->fields->integersIn($game, 'games');
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
                $player = $this->fields->arrayAt($match, $side);

                $id = $this->fields->intAt($player, 'id');
                $name = $this->fields->nonEmptyStringAt($player, 'display_name');

                if (null === $id || null === $name || isset($participants[$id])) {
                    continue;
                }

                $participants[$id] = new ChallongeParticipant(
                    id: $id,
                    participantId: $this->fields->intAt($player, 'participant_id'),
                    seed: $this->fields->intAt($player, 'seed'),
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
}
