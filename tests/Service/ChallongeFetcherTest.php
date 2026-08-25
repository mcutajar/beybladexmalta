<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeUrl;
use App\Exception\ChallongeFetchException;
use App\Service\ChallongeFetcher;
use App\Service\ChallongeModuleParser;
use App\Service\ChallongeSmokeCheck;
use App\Service\ChallongeStandingsParser;
use App\Service\ChallongeStoreNormaliser;
use App\Tests\Support\FakeChallonge;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ChallongeFetcherTest extends TestCase
{
    private const BRACKET = 'https://challonge.com/9yuqg2pi';

    public function testItCapturesTheWholeBracket(): void
    {
        $snapshot = $this->fetcher(new MockResponse(FakeChallonge::modulePage()))
            ->fetch(ChallongeUrl::fromString(self::BRACKET));

        self::assertSame('9yuqg2pi', $snapshot->slug);
        self::assertSame('https://challonge.com/9yuqg2pi/module?show_standings=1', $snapshot->sourceUrl);
        self::assertSame(18169778, $snapshot->tournamentId);
        self::assertSame(7, $snapshot->matchCount());
        self::assertCount(2, $snapshot->stages);
        self::assertTrue($snapshot->hasStandings());
    }

    public function testItStampsTheSnapshotInUtc(): void
    {
        $snapshot = $this->fetcher(new MockResponse(FakeChallonge::modulePage()))
            ->fetch(ChallongeUrl::fromString(self::BRACKET));

        self::assertSame('UTC', $snapshot->fetchedAt->getTimezone()->getName());
        self::assertEqualsWithDelta(time(), $snapshot->fetchedAt->getTimestamp(), 5);
    }

    /**
     * An anonymous client is bounced, so the request has to say who we are.
     */
    public function testItNamesItselfAndAsksForTheStandings(): void
    {
        $response = new MockResponse(FakeChallonge::modulePage());

        $this->fetcher($response)->fetch(ChallongeUrl::fromString(self::BRACKET));

        self::assertSame('GET', $response->getRequestMethod());
        self::assertStringContainsString('show_standings=1', $response->getRequestUrl());

        self::assertContains(
            'User-Agent: MaltaBeybladeLeague/1.0 (+https://github.com/mcutajar/beybladexmalta)',
            $response->getRequestOptions()['headers'],
        );
    }

    public function testItReportsWhatChallongeAnsweredInsteadOfTwoHundred(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('https://challonge.com/9yuqg2pi/module?show_standings=1 answered 403, expected 200.');

        $this->fetcher(new MockResponse('Verifying you are human.', ['http_code' => 403]))
            ->fetch(ChallongeUrl::fromString(self::BRACKET));
    }

    public function testItReportsAFailureToReachChallongeAtAll(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): never {
            throw new TransportException('Could not resolve host.');
        });

        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('Could not reach https://challonge.com/9yuqg2pi/module?show_standings=1: Could not resolve host.');

        $this->fetcherUsing($client)->fetch(ChallongeUrl::fromString(self::BRACKET));
    }

    /**
     * A 200 that is a bot check rather than a bracket has to fail here, not
     * halfway through an import.
     */
    public function testItRefusesAPageThatIsNotABracket(): void
    {
        $this->expectException(ChallongeFetchException::class);

        $this->fetcher(new MockResponse('<html><body>Verifying you are human.</body></html>'))
            ->fetch(ChallongeUrl::fromString(self::BRACKET));
    }

    /**
     * The smoke check runs before the parse, so a page whose ranking stage has
     * no readable standings never becomes a snapshot — there would be no
     * finishing order to import out of it.
     */
    public function testItRefusesABracketWithNoStandingsTable(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('Expected a standings table for the stage that orders the event');

        $this->fetcher(new MockResponse(FakeChallonge::modulePage(withStandings: false)))
            ->fetch(ChallongeUrl::fromString(self::BRACKET));
    }

    /**
     * An abort says what was expected and what came back, because the whole
     * point of checking first is that somebody can act on the answer.
     */
    public function testItAbortsWithWhatChangedRatherThanAParseError(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('The Challonge module page at https://challonge.com/9yuqg2pi/module?show_standings=1 is not the page this reads. Expected a tournament store that decodes as JSON; found a page that says "Verifying you are human", which is a bot check standing in for the bracket.');

        $this->fetcher(new MockResponse('<html><body>Verifying you are human.</body></html>'))
            ->fetch(ChallongeUrl::fromString(self::BRACKET));
    }

    private function fetcher(MockResponse $response): ChallongeFetcher
    {
        return $this->fetcherUsing(new MockHttpClient($response));
    }

    private function fetcherUsing(MockHttpClient $client): ChallongeFetcher
    {
        $parser = new ChallongeModuleParser();
        $standingsParser = new ChallongeStandingsParser();

        return new ChallongeFetcher(
            $client,
            $parser,
            new ChallongeStoreNormaliser($standingsParser),
            new ChallongeSmokeCheck($parser, $standingsParser),
        );
    }
}
