<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ChallongeFetchException;
use App\Service\ChallongeModuleParser;
use PHPUnit\Framework\TestCase;

final class ChallongeModuleParserTest extends TestCase
{
    private ChallongeModuleParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ChallongeModuleParser();
    }

    public function testItReadsTheStoreOutOfTheScriptTag(): void
    {
        $store = $this->parser->readStore($this->page(
            '{"tournament":{"tournament_type":"swiss"},"matches_by_round":{"1":[]}}',
        ));

        self::assertSame(['tournament' => ['tournament_type' => 'swiss'], 'matches_by_round' => ['1' => []]], $store);
    }

    /**
     * The store is one assignment among several on the same line, and the
     * JavaScript that follows it carries braces of its own.
     */
    public function testItStopsAtTheBraceThatClosesTheStore(): void
    {
        $store = $this->parser->readStore($this->page('{"rounds":[{"number":1}]}'));

        self::assertSame(['rounds' => [['number' => 1]]], $store);
    }

    /**
     * A blader called their team `{Bracket}` and the page survives it; so
     * must counting braces.
     */
    public function testItIgnoresBracesAndQuotesInsideStrings(): void
    {
        $store = $this->parser->readStore($this->page(
            '{"display_name":"Guy \"The {Bracket}\" \\\\o/","seed":1}',
        ));

        self::assertSame(['display_name' => 'Guy "The {Bracket}" \\o/', 'seed' => 1], $store);
    }

    public function testItAcceptsTheDoubleQuotedKeyForm(): void
    {
        $page = '<script>window._initialStoreState["TournamentStore"] = {"tournament":{}};</script>';

        self::assertSame(['tournament' => []], $this->parser->readStore($page));
    }

    /**
     * A two-stage bracket assigns the store once per view. The two are
     * identical on the real page, so the first one is the whole answer.
     */
    public function testItTakesTheFirstOfTwoAssignments(): void
    {
        $page = $this->page('{"stage":"first"}').$this->page('{"stage":"second"}');

        self::assertSame(['stage' => 'first'], $this->parser->readStore($page));
    }

    public function testItRejectsAPageWithNoStoreAtAll(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage("carries no _initialStoreState['TournamentStore'] assignment");

        $this->parser->readStore('<html><body>Verifying you are human.</body></html>');
    }

    public function testItRejectsAStoreThatIsNotAnObject(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('not followed by an object');

        $this->parser->readStore('<script>window._initialStoreState[\'TournamentStore\'] = null;</script>');
    }

    public function testItRejectsATruncatedPage(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('never closed');

        $this->parser->readStore('<script>window._initialStoreState[\'TournamentStore\'] = {"tournament":{"id":1}');
    }

    public function testItRejectsAStoreThatIsNotJson(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('did not decode as JSON');

        $this->parser->readStore($this->page('{"tournament": undefined}'));
    }

    public function testItKeepsTheStandingsTableFromThePageBody(): void
    {
        $scorecard = $this->parser->readScorecard(
            "<html><body><div id='scorecard'><table class='standings'><tr><td>Obelix</td></tr></table></div></body></html>",
        );

        self::assertNotNull($scorecard);
        self::assertStringStartsWith('<div id="scorecard">', $scorecard);
        self::assertStringContainsString('Obelix', $scorecard);
        self::assertStringEndsWith('</div>', $scorecard);
    }

    /**
     * Reported rather than raised: a bracket is allowed to have no standings,
     * and it is the caller that knows whether that matters.
     */
    public function testItReportsAPageWithNoStandingsTable(): void
    {
        self::assertNull($this->parser->readScorecard('<html><body><p>No standings here.</p></body></html>'));
    }

    /**
     * The line Challonge actually serves: several stores, then more script.
     */
    private function page(string $store): string
    {
        return sprintf(
            '<script>if (window._initialStoreState === undefined) window._initialStoreState = {};'
            .' window._initialStoreState[\'CurrentUserStore\'] = {"locale":"en"};'
            .' window._initialStoreState[\'TournamentStore\'] = %s;'
            .' window.SomethingElse = {"not":"the store"};</script>',
            $store,
        );
    }
}
