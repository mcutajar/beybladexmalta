<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\BracketAnswers;
use App\Dto\BracketPreview;
use App\Dto\BracketPreviewResult;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeStanding;
use App\Service\BracketPreviewer;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The three brackets this way in cannot read, each refused before anything is
 * derived from it.
 *
 * None of them is a broken bracket. They are the shapes where a preview would
 * have to invent the half it is missing, and the point of refusing by name is
 * that the screen says which one it is rather than rendering an empty table.
 */
#[ResetDatabase]
final class BracketPreviewerTest extends ServiceTestCase
{
    public function testANewBladerIsVisibleToTheNextPreview(): void
    {
        $first = PlayerFactory::createOne(['name' => 'First']);
        $previewer = $this->service(BracketPreviewer::class);

        $previewer->preview(
            $this->bracket(),
            'challonge.com/probe123',
            'A Probe',
            '2026-08-16',
            'a-season',
            new BracketAnswers(['somebody' => BracketAnswers::linkTo($first->getId())]),
        );

        $second = PlayerFactory::createOne(['name' => 'Second']);
        $preview = $previewer->preview(
            $this->bracket(),
            'challonge.com/probe123',
            'A Probe',
            '2026-08-16',
            'a-season',
            new BracketAnswers(['somebody' => BracketAnswers::linkTo($second->getId())]),
        );

        self::assertSame('Second', $preview->placements[0]->bladerName);
        self::assertContains("php bin/console app:alias add 'Second' 'Somebody'", $preview->ledger);
    }

    public function testATeamBracketIsRefusedBecauseItsEntrantsAreTeams(): void
    {
        $preview = $this->preview($this->bracket(isTeam: true));

        self::assertFalse($preview->isReady());
        self::assertSame(BracketPreviewResult::TeamEvent, $preview->result);
        self::assertStringContainsString('--team', $preview->refusal());
    }

    public function testABracketWithNoStandingsStatesNoFinishingOrder(): void
    {
        $preview = $this->preview($this->bracket(withStandings: false));

        self::assertFalse($preview->isReady());
        self::assertSame(BracketPreviewResult::NoStandings, $preview->result);
        self::assertStringContainsString('app:challonge-smoke', $preview->refusal());
    }

    /**
     * `app:import-tournament` has no guard of its own — a second import
     * inserts a fresh tournament and a full set of results — so the refusal
     * has to live here, ahead of it.
     */
    public function testABracketAnEventAlreadyNamesIsRefused(): void
    {
        TournamentFactory::createOne([
            'title' => 'The first time round',
            'season' => SeasonFactory::createOne(['slug' => 'a-season']),
            'challongeUrl' => 'https://challonge.com/vi/probe123',
        ]);

        $preview = $this->preview($this->bracket());

        self::assertFalse($preview->isReady());
        self::assertSame(BracketPreviewResult::AlreadyImported, $preview->result);
        self::assertStringContainsString('app:fetch-challonge', $preview->refusal());
    }

    private function preview(ChallongeSnapshot $snapshot): BracketPreview
    {
        return $this->service(BracketPreviewer::class)->preview(
            $snapshot,
            'challonge.com/probe123',
            'A Probe',
            '2026-08-16',
            'a-season',
        );
    }

    /**
     * The smallest bracket that is still one: no entrants and no matches, so
     * every assertion here is about the refusal rather than about the parse.
     */
    private function bracket(bool $isTeam = false, bool $withStandings = true): ChallongeSnapshot
    {
        return new ChallongeSnapshot(
            slug: 'probe123',
            sourceUrl: 'https://challonge.com/probe123/module?show_standings=1',
            fetchedAt: new \DateTimeImmutable('2026-08-16 12:00:00'),
            tournamentId: 1,
            tournamentType: 'swiss',
            tournamentState: 'complete',
            isTeamTournament: $isTeam,
            stages: [
                new ChallongeStage(
                    kind: ChallongeStageKind::Single,
                    name: null,
                    format: 'swiss',
                    rounds: [],
                    participants: [],
                    matches: [],
                    standings: $withStandings ? [$this->standing()] : [],
                ),
            ],
        );
    }

    private function standing(): ChallongeStanding
    {
        return new ChallongeStanding(
            rank: 1,
            name: 'Somebody',
            challongeUser: null,
            labels: [],
            matchIds: [],
            columns: [],
        );
    }
}
