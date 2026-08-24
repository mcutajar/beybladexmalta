<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeJoin;
use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongePlacing;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeStanding;
use App\Service\ChallongeStandingsResolver;
use PHPUnit\Framework\TestCase;

final class ChallongeStandingsResolverTest extends TestCase
{
    private ChallongeStandingsResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ChallongeStandingsResolver();
    }

    /**
     * The join that matters. A blader who linked their Challonge account is
     * rendered as that account in the standings table and under their own name
     * in every match, so `Sanya0207` and `legion` are the same person and no
     * amount of string comparison will say so. The matches they appear in
     * will: intersect the players of all five and only one name is in all of
     * them.
     */
    public function testItJoinsARowToTheOnlyEntrantWhoPlayedAllOfItsMatches(): void
    {
        $placings = $this->resolver->resolve($this->swissStage([
            $this->standing(rank: 1, name: null, challongeUser: 'Sanya0207', matchIds: [901, 902]),
        ]));

        self::assertSame('legion', $placings[0]->name());
        self::assertSame(ChallongeJoin::MatchIds, $placings[0]->join);
        self::assertTrue($placings[0]->isResolved());
    }

    /**
     * One match narrows a row to the two people who played it and no further,
     * which is every entrant knocked out in the first round of a cut. There
     * the name is all there is.
     */
    public function testItFallsBackToTheNameWhenOneMatchLeavesTwoCandidates(): void
    {
        $placings = $this->resolver->resolve($this->swissStage([
            $this->standing(rank: 4, name: 'obelix', challongeUser: null, matchIds: [902]),
        ]));

        self::assertSame('Obelix', $placings[0]->name());
        self::assertSame(ChallongeJoin::Name, $placings[0]->join);
    }

    /**
     * The standings table of a one-stage bracket carries no match history at
     * all, so every row in one arrives with nothing to intersect.
     */
    public function testItFallsBackToTheNameWhenTheTableCarriesNoMatchHistory(): void
    {
        $placings = $this->resolver->resolve($this->swissStage([
            $this->standing(rank: 1, name: null, challongeUser: 'Sanya0207', matchIds: []),
            $this->standing(rank: 2, name: 'GIGLIO', challongeUser: null, matchIds: []),
        ]));

        self::assertSame([null, 'Giglio'], array_map(
            static fn (ChallongePlacing $placing): ?string => $placing->participant?->name,
            $placings,
        ));
        self::assertSame('Sanya0207', $placings[0]->name());
    }

    /**
     * The group and final stages use disjoint id spaces, so a cell pointing at
     * a match this stage does not hold says nothing about it — and must not
     * empty the intersection and take the row's real answer with it.
     */
    public function testItIgnoresAMatchTheStageDoesNotHold(): void
    {
        $placings = $this->resolver->resolve($this->swissStage([
            $this->standing(rank: 1, name: null, challongeUser: 'Sanya0207', matchIds: [901, 902, 55555]),
        ]));

        self::assertSame('legion', $placings[0]->name());
        self::assertSame(ChallongeJoin::MatchIds, $placings[0]->join);
    }

    public function testItMatchesANameWhateverItsCase(): void
    {
        $placings = $this->resolver->resolve($this->swissStage([
            $this->standing(rank: 2, name: 'gIgLiO', challongeUser: null, matchIds: []),
        ]));

        self::assertSame('Giglio', $placings[0]->name());
    }

    /**
     * A row nobody can be attached to is kept rather than dropped: a standings
     * table with a row missing is a worse record than one with a row nobody
     * has claimed, and the rank still happened.
     */
    public function testItKeepsARowItCannotJoinToAnybody(): void
    {
        $placings = $this->resolver->resolve($this->swissStage([
            $this->standing(rank: 9, name: 'a blader who never played', challongeUser: null, matchIds: []),
        ]));

        self::assertCount(1, $placings);
        self::assertNull($placings[0]->participant);
        self::assertSame(ChallongeJoin::None, $placings[0]->join);
        self::assertFalse($placings[0]->isResolved());
        self::assertSame(9, $placings[0]->rank());
        self::assertSame('a blader who never played', $placings[0]->name());
    }

    /**
     * Two entrants spelled the same way is the one case where the name cannot
     * decide either, so nothing is picked rather than the first of them.
     */
    public function testItPicksNobodyWhenTwoEntrantsShareAName(): void
    {
        $stage = new ChallongeStage(
            kind: ChallongeStageKind::Single,
            name: null,
            format: 'swiss',
            rounds: [],
            participants: [
                new ChallongeParticipant(id: 1, participantId: null, seed: 1, name: 'Kaori'),
                new ChallongeParticipant(id: 2, participantId: null, seed: 2, name: 'kaori'),
            ],
            matches: [],
            standings: [$this->standing(rank: 1, name: 'Kaori', challongeUser: null, matchIds: [])],
        );

        self::assertSame(ChallongeJoin::None, $this->resolver->resolve($stage)[0]->join);
    }

    /**
     * The finishing order of an event is the order of the stage everybody was
     * in, not the eight-person cut that followed it.
     */
    public function testTheFinishingOrderIsTheRankingStageNotTheCut(): void
    {
        $snapshot = new ChallongeSnapshot(
            slug: 'co5nncw8',
            sourceUrl: 'https://challonge.com/co5nncw8/module?show_standings=1',
            fetchedAt: new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
            tournamentId: 18113372,
            tournamentType: 'single elimination',
            tournamentState: 'complete',
            isTeamTournament: false,
            stages: [
                $this->swissStage([
                    $this->standing(rank: 1, name: null, challongeUser: 'Sanya0207', matchIds: [901, 902]),
                    $this->standing(rank: 2, name: 'Obelix', challongeUser: null, matchIds: [902]),
                ]),
                new ChallongeStage(
                    kind: ChallongeStageKind::Final,
                    name: null,
                    format: 'single elimination',
                    rounds: [],
                    participants: [new ChallongeParticipant(id: 500, participantId: null, seed: 1, name: 'Obelix')],
                    matches: [],
                    standings: [$this->standing(rank: 1, name: 'Obelix', challongeUser: null, matchIds: [])],
                ),
            ],
        );

        self::assertSame(['legion', 'Obelix'], array_map(
            static fn (ChallongePlacing $placing): ?string => $placing->name(),
            $this->resolver->finishingOrder($snapshot),
        ));
    }

    /**
     * `legion` and `Obelix` played each other in match 902, so only `legion`
     * is in both of the matches the first row lists.
     *
     * @param list<ChallongeStanding> $standings
     */
    private function swissStage(array $standings): ChallongeStage
    {
        return new ChallongeStage(
            kind: ChallongeStageKind::Group,
            name: 'Group A',
            format: 'swiss',
            rounds: [],
            participants: [
                new ChallongeParticipant(id: 1, participantId: null, seed: 1, name: 'legion'),
                new ChallongeParticipant(id: 2, participantId: null, seed: 2, name: 'Giglio'),
                new ChallongeParticipant(id: 3, participantId: null, seed: 3, name: 'Obelix'),
            ],
            matches: [
                $this->match(id: 901, player1Id: 1, player2Id: 2),
                $this->match(id: 902, player1Id: 3, player2Id: 1),
            ],
            standings: $standings,
        );
    }

    private function match(int $id, int $player1Id, int $player2Id): ChallongeMatch
    {
        return new ChallongeMatch(
            id: $id,
            round: 1,
            identifier: 'A',
            state: 'complete',
            player1Id: $player1Id,
            player2Id: $player2Id,
            games: [[7, 4]],
            score: [7, 4],
            winnerId: $player1Id,
            loserId: $player2Id,
            forfeited: false,
            consolation: false,
        );
    }

    /**
     * @param list<int> $matchIds
     */
    private function standing(int $rank, ?string $name, ?string $challongeUser, array $matchIds): ChallongeStanding
    {
        return new ChallongeStanding(
            rank: $rank,
            name: $name,
            challongeUser: $challongeUser,
            labels: [],
            matchIds: $matchIds,
            columns: [],
        );
    }
}
