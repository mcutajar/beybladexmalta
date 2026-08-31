<?php

declare(strict_types=1);

namespace App\Tests\Challonge;

use App\Entity\Season;
use App\Entity\Tournament;
use App\Service\ChallongeArchiveService;
use App\Service\ChallongeSnapshotReader;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\PageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The records board, against brackets the league actually played.
 *
 * `LeagueRecordsPresenterTest` owns the counting rules on matches built to
 * order. This one owns the thing that cannot be built to order: two real
 * evenings, filed under two seasons, and a blader whose sixteen matches are
 * eight in each. Every scope claim on #59 is a claim about that shape.
 *
 * Giglio entered both as `giglio15 (invitation pending)`, so the alias is the
 * only thing joining the two halves of his record together — which is the
 * epic's central thesis, and worth having the board depend on.
 */
#[WithStory(SeasonStory::class)]
final class ArchivedRecordsBoardTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    /** Giglio's two evenings: 8 matches and 6 wins in each. */
    private const JULY_5 = '9yuqg2pi';

    private const JULY_12 = 'zx9el0js';

    public function testOverallUsesTheRecordsTitle(): void
    {
        $this->archiveBothEvenings();

        self::assertSame('Malta Beyblade Community Records', $this->headline($this->board()));
    }

    public function testASeasonUsesTheSameRecordsTitle(): void
    {
        $this->archiveBothEvenings();

        self::assertSame('Malta Beyblade Community Records', $this->headline($this->board('paid-season')));
    }

    /**
     * The acceptance criterion this page turns on: eligibility is evaluated
     * inside the scope being asked for. Giglio has sixteen matches in the
     * league and eight in either season, so he holds the overall win rate and
     * nobody holds the seasonal one.
     */
    public function testACareerTotalCannotQualifyForASeasonRecord(): void
    {
        $this->archiveBothEvenings();

        $overall = $this->tile($this->board(), 'win-rate');
        self::assertStringContainsString('Giglio', $overall);
        self::assertStringContainsString('75%', $overall);
        self::assertStringContainsString('12–4 in 16 matches', $overall);

        self::assertStringContainsString(
            'Nobody has set this one yet',
            $this->tile($this->board('paid-season'), 'win-rate'),
        );
    }

    /**
     * Season scoping is applied to the record values themselves, not only to
     * which bladers qualify.
     */
    public function testARecordValueIsRecomputedInTheScope(): void
    {
        $this->archiveBothEvenings();

        self::assertStringContainsString('16', $this->tile($this->board(), 'matches'));
        self::assertStringContainsString('8', $this->tile($this->board('paid-season'), 'matches'));
    }

    public function testEveryRecordIsExpandableIntoItsRankedValues(): void
    {
        $this->archiveBothEvenings();

        $page = $this->board();
        $tile = $page->filter('[data-record="matches"]');

        self::assertStringNotContainsString('A win rate is a record once', $page->text());
        self::assertStringContainsString('self-start', (string) $tile->attr('class'));
        self::assertCount(1, $tile->filter('summary'));
        self::assertCount(1, $tile->filter('ol > li'));
        self::assertStringContainsString('Giglio', $tile->filter('ol > li')->first()->text());
        self::assertStringContainsString('16', $tile->filter('ol > li')->first()->text());
    }

    /**
     * League points are season-specific and a record is not. The board reads
     * archived matches and never `TournamentResult`, in either scope.
     */
    public function testNoLeaguePointsReachTheBoard(): void
    {
        $event = $this->archive(self::JULY_12, SeasonStory::paymentSeason());

        TournamentResultFactory::createOne([
            'bonusPoints' => 4242,
            'f1Points' => 8484,
            'player' => PlayerFactory::createOne(['name' => 'Giglio']),
            'rank' => 1,
            'tournament' => $event,
        ]);

        foreach ([null, 'paid-season'] as $scope) {
            $page = $this->board($scope)->text();

            self::assertStringNotContainsString('4242', $page);
            self::assertStringNotContainsString('8484', $page);
        }
    }

    /**
     * A URL that names a season nobody has heard of is a 404, not the overall
     * board wearing a wrong label — a page that answers a wrong URL with a
     * different scope's numbers is a page nobody can cite.
     */
    public function testAnUnknownSeasonIsNotFound(): void
    {
        $this->createBrowser()->request('GET', '/records?season=no-such-season');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The board against a database with nothing archived, which is every
     * database until the backfill has run.
     */
    public function testAnEmptyArchiveRendersAnEmptyBoard(): void
    {
        $page = $this->board();

        self::assertCount(1, $page->filter('[data-page-section="records-empty"]'));
        self::assertCount(0, $page->filter('[data-record]'));

        // The selector is still there, because the way out of an empty scope
        // is to choose another one.
        self::assertCount(1, $page->filter('[data-page-section="scope"] nav'));
    }

    public function testTheActiveScopeIsMarkedInTheSelector(): void
    {
        $current = $this->board('free-season')->filter('[data-page-section="scope"] a[aria-current="page"]');

        self::assertCount(1, $current);
        self::assertSame('Free Season', trim($current->text()));
    }

    /**
     * The Overall board has no season to go back to, so it goes to the index
     * — which is what #94 made the answer to "everything". A season-scoped
     * board goes back to that season's own table.
     */
    public function testTheBoardReturnsToTheIndexOverallAndToTheSeasonWhenScoped(): void
    {
        $overall = $this->board()->filter('a')->reduce(
            static fn (Crawler $link): bool => str_contains($link->text(), 'Return to every season'),
        );

        self::assertCount(1, $overall);
        self::assertSame('/seasons', $overall->attr('href'));

        $scoped = $this->board('free-season')->filter('a')->reduce(
            static fn (Crawler $link): bool => str_contains($link->text(), 'Return to Free Season leaderboard'),
        );

        self::assertCount(1, $scoped);
        self::assertSame('/season/free-season', $scoped->attr('href'));
    }

    private function archiveBothEvenings(): void
    {
        $giglio = PlayerFactory::createOne(['name' => 'Giglio']);
        PlayerAliasFactory::createOne(['alias' => 'giglio15', 'player' => $giglio]);

        $this->archive(self::JULY_5, SeasonStory::freeSeason());
        $this->archive(self::JULY_12, SeasonStory::paymentSeason());
    }

    private function archive(string $slug, Season $season): Tournament
    {
        $event = TournamentFactory::createOne([
            'title' => 'Event from '.$slug,
            'season' => $season,
            'challongeUrl' => 'https://challonge.com/'.$slug,
        ]);

        self::getContainer()->get(ChallongeArchiveService::class)->archive(
            $event,
            self::getContainer()->get(ChallongeSnapshotReader::class)->read($slug),
        );

        return $event;
    }

    private function board(?string $season = null): Crawler
    {
        $crawler = $this->createBrowser()->request(
            'GET',
            null === $season ? '/records' : '/records?season='.$season,
        );

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function headline(Crawler $page): string
    {
        return trim($page->filter('h1')->text());
    }

    private function tile(Crawler $page, string $record): string
    {
        $tile = $page->filter(sprintf('[data-record="%s"]', $record));

        self::assertCount(1, $tile, sprintf('Expected one "%s" tile on the board.', $record));

        return (string) preg_replace('/\s+/', ' ', $tile->text());
    }
}
