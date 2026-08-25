<?php

declare(strict_types=1);

namespace App\Tests\Dto;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Exception\UnsupportedChallongeBracketException;
use PHPUnit\Framework\TestCase;

/**
 * The three questions a snapshot is asked about its own shape: which stage
 * ordered the event, which one was the cut, and who won it.
 */
final class ChallongeSnapshotTest extends TestCase
{
    public function testTheRankingStageIsTheGroupWhenThereIsACut(): void
    {
        $snapshot = $this->snapshot([
            $this->stage(ChallongeStageKind::Group, 'swiss'),
            $this->stage(ChallongeStageKind::Final, 'single elimination'),
        ]);

        self::assertSame('swiss', $snapshot->rankingStage()?->format);
        self::assertSame('single elimination', $snapshot->cutStage()?->format);
    }

    public function testAOneStageBracketIsItsOwnRankingStageAndHasNoCut(): void
    {
        $snapshot = $this->snapshot([$this->stage(ChallongeStageKind::Single, 'round robin')]);

        self::assertSame('round robin', $snapshot->rankingStage()?->format);
        self::assertNull($snapshot->cutStage());
        self::assertNull($snapshot->knockoutWinner());
    }

    /**
     * A pools event would be `[pool A, pool B, final]`, and answering with pool
     * A would present a finishing order for a third of the entrants as one for
     * all of them — wrong, with nothing out of place to notice. The league does
     * not run pools; the day it does, this is a decision to be made rather than
     * inherited.
     */
    public function testItRefusesToOrderABracketWithMoreThanOneGroup(): void
    {
        $snapshot = $this->snapshot([
            $this->stage(ChallongeStageKind::Group, 'swiss'),
            $this->stage(ChallongeStageKind::Group, 'swiss'),
            $this->stage(ChallongeStageKind::Final, 'single elimination'),
        ]);

        $this->expectException(UnsupportedChallongeBracketException::class);
        $this->expectExceptionMessage('The bracket "co5nncw8" has 2 group stages, and there is no rule yet for how pools combine into one finishing order.');

        $snapshot->rankingStage();
    }

    /**
     * The third-place playoff is played after the final, so the last match of
     * a cut is not the one that decided it.
     */
    public function testTheKnockoutWinnerIsNotWhoeverPlayedLast(): void
    {
        $cut = new ChallongeStage(
            kind: ChallongeStageKind::Final,
            name: null,
            format: 'single elimination',
            rounds: [],
            participants: [
                new ChallongeParticipant(id: 1, participantId: null, seed: 1, name: 'Rizzler'),
                new ChallongeParticipant(id: 2, participantId: null, seed: 2, name: 'Giglio'),
                new ChallongeParticipant(id: 3, participantId: null, seed: 3, name: 'Obelix'),
            ],
            matches: [
                $this->match(id: 10, round: 2, winnerId: 1, loserId: 2),
                $this->match(id: 11, round: 2, winnerId: 3, loserId: 2, consolation: true),
            ],
            standings: [],
        );

        self::assertSame('Rizzler', $this->snapshot([$cut])->knockoutWinner()?->name);
    }

    public function testACutNobodyFinishedNamesNoWinner(): void
    {
        $cut = new ChallongeStage(
            kind: ChallongeStageKind::Final,
            name: null,
            format: 'single elimination',
            rounds: [],
            participants: [new ChallongeParticipant(id: 1, participantId: null, seed: 1, name: 'Rizzler')],
            matches: [$this->match(id: 10, round: 1, winnerId: null, loserId: null, state: 'open')],
            standings: [],
        );

        self::assertNull($this->snapshot([$cut])->knockoutWinner());
    }

    /**
     * A forfeit is complete and has a winner, but nobody played it.
     */
    public function testItCountsOnlyTheMatchesSomebodyContested(): void
    {
        $stage = new ChallongeStage(
            kind: ChallongeStageKind::Single,
            name: null,
            format: 'swiss',
            rounds: [],
            participants: [],
            matches: [
                $this->match(id: 10, round: 1, winnerId: 1, loserId: 2),
                $this->match(id: 11, round: 1, winnerId: 1, loserId: 2, forfeited: true),
                $this->match(id: 12, round: 2, winnerId: null, loserId: null, state: 'pending'),
            ],
            standings: [],
        );

        $snapshot = $this->snapshot([$stage]);

        self::assertSame(3, $snapshot->matchCount());
        self::assertSame(1, $snapshot->playedMatchCount());
    }

    /**
     * @param list<ChallongeStage> $stages
     */
    private function snapshot(array $stages): ChallongeSnapshot
    {
        return new ChallongeSnapshot(
            slug: 'co5nncw8',
            sourceUrl: 'https://challonge.com/co5nncw8/module?show_standings=1',
            fetchedAt: new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
            tournamentId: 18113372,
            tournamentType: 'single elimination',
            tournamentState: 'complete',
            isTeamTournament: false,
            stages: $stages,
        );
    }

    private function stage(ChallongeStageKind $kind, string $format): ChallongeStage
    {
        return new ChallongeStage(
            kind: $kind,
            name: null,
            format: $format,
            rounds: [],
            participants: [],
            matches: [],
            standings: [],
        );
    }

    private function match(
        int $id,
        int $round,
        ?int $winnerId,
        ?int $loserId,
        string $state = 'complete',
        bool $forfeited = false,
        bool $consolation = false,
    ): ChallongeMatch {
        return new ChallongeMatch(
            id: $id,
            round: $round,
            identifier: 'A',
            state: $state,
            player1Id: 1,
            player2Id: 2,
            games: [[7, 4]],
            score: [7, 4],
            winnerId: $winnerId,
            loserId: $loserId,
            forfeited: $forfeited,
            consolation: $consolation,
        );
    }
}
