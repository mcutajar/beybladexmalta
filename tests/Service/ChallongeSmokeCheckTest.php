<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeSmokeFinding;
use App\Dto\ChallongeSmokeOutcome;
use App\Dto\ChallongeSmokeReport;
use App\Service\ChallongeModuleParser;
use App\Service\ChallongeSmokeCheck;
use App\Service\ChallongeStandingsParser;
use App\Tests\Support\FakeChallonge;
use PHPUnit\Framework\TestCase;

/**
 * The check, put to the module page the fixtures hold and then to the same
 * page with one piece taken out of it.
 *
 * The corruptions are made here rather than committed as files of their own,
 * because a corrupted fixture is only worth anything while it is otherwise
 * identical to the real one. Each is the real page with one field renamed,
 * emptied or removed — which is the shape of the change this whole check
 * exists to survive.
 */
final class ChallongeSmokeCheckTest extends TestCase
{
    private ChallongeModuleParser $parser;

    private ChallongeSmokeCheck $check;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ChallongeModuleParser();
        $this->check = new ChallongeSmokeCheck($this->parser, new ChallongeStandingsParser());
    }

    public function testTheFixtureBracketPassesEveryExpectation(): void
    {
        $report = $this->check->check(FakeChallonge::modulePage(), 'the fixture');

        self::assertTrue($report->passed(), $report->problem());
        self::assertSame('', $report->problem());

        foreach ($report->findings as $finding) {
            self::assertSame(ChallongeSmokeOutcome::Passed, $finding->outcome, $finding->expectation);
        }
    }

    /**
     * A passing run still says what it read, so a change that is not a failure
     * — a round fewer, a standings table half the size — is visible to
     * somebody watching the scheduled run.
     */
    public function testAPassingRunSaysWhatItFound(): void
    {
        $report = $this->check->check(FakeChallonge::modulePage(), 'the fixture');

        self::assertSame(
            [
                '29 KB of HTML.',
                'a store carrying requested_plotter, tournament, rounds, third_place_match, consolation_matches, matches_by_round, groups.',
                'tournament 18169778, a single elimination bracket, complete.',
                '2 in the group stage "Group A".',
                '3 across 2 rounds of the group stage "Group A".',
                '8 matches, 7 of them carrying a winner and a scoreline.',
                '4 rows for the group stage "Group A".',
            ],
            array_map(static fn (ChallongeSmokeFinding $finding): string => $finding->detail, $report->findings),
        );
    }

    public function testItRefusesAResponseThatIsNotAPage(): void
    {
        $report = $this->check->check('{"error":"unauthorized"}', 'the fixture');

        self::assertSame('an HTML page', $this->failureOf($report)->expectation);
        self::assertStringContainsString('{"error":"unauthorized"}', $this->failureOf($report)->detail);
    }

    public function testItRefusesAnEmptyResponse(): void
    {
        self::assertSame('an empty response.', $this->failureOf($this->check->check('   ', 'the fixture'))->detail);
    }

    /**
     * The one failure that is not a change of shape: Challonge deciding we are
     * a robot. It looks exactly like a missing tournament store, so the check
     * says which of the two it is rather than leaving somebody to guess.
     */
    public function testItNamesABotCheckStandingInForTheBracket(): void
    {
        $report = $this->check->check(
            '<html><body><h1>Just a moment...</h1><p>Verifying you are human.</p></body></html>',
            'the fixture',
        );

        self::assertSame('a tournament store that decodes as JSON', $this->failureOf($report)->expectation);
        self::assertStringContainsString('Verifying you are human', $this->failureOf($report)->detail);
        self::assertStringContainsString('bot check', $this->failureOf($report)->detail);
    }

    public function testItRefusesAPageCarryingNoTournamentStore(): void
    {
        $report = $this->check->check('<html><body>Nothing to see.</body></html>', 'the fixture');

        self::assertSame('a tournament store that decodes as JSON', $this->failureOf($report)->expectation);
        self::assertStringContainsString("_initialStoreState['TournamentStore']", $this->failureOf($report)->detail);
    }

    /**
     * Everything after the store is looked for independently, so the first
     * broken field does not hide the next one. Everything before it is a
     * prerequisite, and what could not be read is said to be unread rather
     * than reported as six more failures.
     */
    public function testAMissingStoreLeavesTheRestUnread(): void
    {
        $report = $this->check->check('<html><body>Nothing to see.</body></html>', 'the fixture');

        self::assertCount(7, $report->findings);
        self::assertSame(
            [
                ChallongeSmokeOutcome::Passed,
                ChallongeSmokeOutcome::Failed,
                ChallongeSmokeOutcome::NotRun,
                ChallongeSmokeOutcome::NotRun,
                ChallongeSmokeOutcome::NotRun,
                ChallongeSmokeOutcome::NotRun,
                ChallongeSmokeOutcome::NotRun,
            ],
            array_map(static fn (ChallongeSmokeFinding $finding): ChallongeSmokeOutcome => $finding->outcome, $report->findings),
        );
    }

    public function testItRefusesAStoreWithNoTournamentType(): void
    {
        $store = $this->fixtureStore();
        unset($store['tournament']['tournament_type']);

        $report = $this->check->check($this->pageFor($store), 'the fixture');

        self::assertSame('a tournament with an id and a format', $this->failureOf($report)->expectation);
        self::assertSame('a tournament whose "tournament_type" is null.', $this->failureOf($report)->detail);
    }

    public function testItRefusesABracketWithNoRounds(): void
    {
        $store = $this->fixtureStore();
        $store['groups'][0]['rounds'] = [];

        $report = $this->check->check($this->pageFor($store), 'the fixture');

        self::assertSame('at least one round', $this->failureOf($report)->expectation);
        self::assertSame('the group stage "Group A", whose "rounds" is empty.', $this->failureOf($report)->detail);
    }

    public function testItRefusesABracketWhoseMatchesAreGone(): void
    {
        $store = $this->fixtureStore();
        $store['groups'][0]['matches_by_round'] = [];

        $report = $this->check->check($this->pageFor($store), 'the fixture');

        self::assertSame('at least one match', $this->failureOf($report)->expectation);
        self::assertSame('the group stage "Group A", whose "matches_by_round" holds no matches.', $this->failureOf($report)->detail);
    }

    /**
     * The rename this is really watching for. A field that disappeared would
     * otherwise read as null all the way down and produce a snapshot quietly
     * missing every scoreline.
     */
    public function testItNamesTheMatchFieldThatWentMissing(): void
    {
        $store = $this->fixtureStore();
        $match = &$store['groups'][0]['matches_by_round']['1'][0];
        $match['score_line'] = $match['scores'];
        unset($match['scores']);

        $report = $this->check->check($this->pageFor($store), 'the fixture');

        self::assertSame('matches carrying both players, the scores and a winner', $this->failureOf($report)->expectation);
        self::assertStringContainsString('carrying no "scores" field', $this->failureOf($report)->detail);
        self::assertStringContainsString('score_line', $this->failureOf($report)->detail);
    }

    public function testItRefusesAMatchWhoseWinnerIsNoLongerANumber(): void
    {
        $store = $this->fixtureStore();
        $store['groups'][0]['matches_by_round']['1'][0]['winner_id'] = 'Obelix';

        $report = $this->check->check($this->pageFor($store), 'the fixture');

        self::assertStringContainsString('whose "winner_id" is string', $this->failureOf($report)->detail);
    }

    /**
     * `show_standings=1` is on every URL this app builds, so a bracket that
     * renders no table even then is one nothing can rank.
     */
    public function testItRefusesABracketThatRendersNoStandings(): void
    {
        $report = $this->check->check(FakeChallonge::modulePage(withStandings: false), 'the fixture');

        self::assertSame('a standings table for the stage that orders the event', $this->failureOf($report)->expectation);
        self::assertStringContainsString('show_standings=1', $this->failureOf($report)->detail);
    }

    /**
     * A table that is still rendered but no longer parses is the same problem
     * arriving quietly, and is the one a fetch would otherwise write to disk.
     */
    public function testItRefusesAStandingsTableWhoseRowsNoLongerParse(): void
    {
        $store = $this->fixtureStore();
        $store['groups'][0]['scorecard_html'] = "<div id='scorecard'><p>Standings are moving to a new home.</p></div>";

        $report = $this->check->check($this->pageFor($store), 'the fixture');

        self::assertSame('a standings table for the stage that orders the event', $this->failureOf($report)->expectation);
        self::assertStringContainsString('parses to no rows', $this->failureOf($report)->detail);
    }

    /**
     * Where the standings are is the one thing that depends on the shape the
     * bracket claims to be: a group renders them into the store, and a bracket
     * with no groups renders them into the page body instead.
     */
    public function testAOneStageBracketIsCheckedAgainstThePageBody(): void
    {
        $store = $this->fixtureStore();
        $scorecard = $store['groups'][0]['scorecard_html'];
        unset($store['groups']);

        $report = $this->check->check($this->pageFor($store, $scorecard), 'the fixture');

        self::assertTrue($report->passed(), $report->problem());
        self::assertSame('4 rows for the bracket.', $report->findings[6]->detail);
    }

    public function testAOneStageBracketWithNothingInTheBodyIsRefused(): void
    {
        $store = $this->fixtureStore();
        unset($store['groups']);

        $report = $this->check->check($this->pageFor($store), 'the fixture');

        self::assertSame('a standings table for the stage that orders the event', $this->failureOf($report)->expectation);
        self::assertStringContainsString('the bracket with no standings table', $this->failureOf($report)->detail);
    }

    /**
     * The sentence a fetch aborts with, and the one the scheduled run puts in
     * front of somebody who was not expecting to hear from it.
     */
    public function testTheProblemSaysWhatWasExpectedAndWhatCameBack(): void
    {
        $report = $this->check->check(
            '<html><body>Nothing to see.</body></html>',
            'https://challonge.com/fixture1/module?show_standings=1',
        );

        self::assertStringContainsString('https://challonge.com/fixture1/module?show_standings=1', $report->problem());
        self::assertStringContainsString('Expected a tournament store that decodes as JSON;', $report->problem());
        self::assertStringContainsString("found the page carries no _initialStoreState['TournamentStore']", $report->problem());
    }

    private function failureOf(ChallongeSmokeReport $report): ChallongeSmokeFinding
    {
        $failure = $report->failure();

        self::assertNotNull($failure, 'The page was expected to fail the smoke check.');

        return $failure;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixtureStore(): array
    {
        return $this->parser->readStore(FakeChallonge::modulePage());
    }

    /**
     * The fixture's own tournament store, put back into a page. Enough of one
     * for the check, which reads exactly two things off a module page: the
     * store, and the standings table under #scorecard.
     *
     * @param array<string, mixed> $store
     */
    private function pageFor(array $store, ?string $bodyScorecard = null): string
    {
        return sprintf(
            "<html><body>%s<script>window._initialStoreState['TournamentStore'] = %s;</script></body></html>",
            null === $bodyScorecard ? '' : sprintf("<div id='scorecard'>%s</div>", $bodyScorecard),
            json_encode($store, \JSON_THROW_ON_ERROR),
        );
    }
}
