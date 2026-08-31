<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Season;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Support\PageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * `/seasons` and what the leaderboard does — and does not — offer.
 *
 * Two decisions are under test here and both are about what is *absent*: the
 * leaderboard has no Overall scope, because points are season-specific and an
 * all-time points table could only be manufactured; and its standings table is
 * never collapsed, because a blader in 18th place finding their own name is
 * the point of the page.
 */
final class SeasonsIndexTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    public function testItRendersWithNoSeasonsAtAll(): void
    {
        $page = $this->createBrowser()->request('GET', '/seasons');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-page-section="seasons-empty"]'));
    }

    public function testItListsEverySeasonNewestFirstWithItsFiguresAndItsWinner(): void
    {
        $preseason = $this->season('preseason-1', 'Preseason 1');
        $season = $this->season('1', 'Season 1');

        $this->scoredEvent($preseason, 'Gamebreaker 20-06', '2026-06-20', 'Markinu', 60);
        $this->scoredEvent($season, 'Gamesplus 04-07', '2026-07-04', 'Markinu', 25);
        $this->scoredEvent($season, 'Gamesplus 23-08', '2026-08-23', 'Giglio', 35);

        $page = $this->createBrowser()->request('GET', '/seasons');

        self::assertResponseIsSuccessful();
        self::assertSame(['1', 'preseason-1'], $this->cards($page));

        $current = $page->filter('[data-season="1"]');

        self::assertStringContainsString('Season 1', $current->text());
        self::assertStringContainsString('Current', $current->text());
        self::assertStringContainsString('2026-07-04', $current->text());
        self::assertStringContainsString('2026-08-23', $current->text());
        self::assertStringContainsString('Leading', $current->text());
        self::assertStringContainsString('Giglio', $current->text());
        self::assertStringContainsString('35 pts', $current->text());

        $past = $page->filter('[data-season="preseason-1"]');

        self::assertStringContainsString('Won by', $past->text());
        self::assertStringContainsString('Markinu', $past->text());
        self::assertStringContainsString('60 pts', $past->text());
    }

    public function testEachCardReachesItsSeasonLeaderboard(): void
    {
        $this->season('preseason-1', 'Preseason 1');
        $this->season('1', 'Season 1');

        $page = $this->createBrowser()->request('GET', '/seasons');

        self::assertCount(1, $page->filter('a[href="/season/1"]'));

        /*
         * `/season` rather than `/season/preseason-1`: that slug is the route's
         * default and Symfony drops a trailing default from a generated URL.
         * Pre-existing, and the page it opens is the same one.
         */
        self::assertCount(1, $page->filter('a[href="/season"]'));
    }

    /**
     * The two genuinely all-time destinations, which are match-derived. There
     * is no all-time points table here and there is not meant to be.
     */
    public function testItPointsAtTheArchiveAndTheRecordsBoardRatherThanAnAllTimeTable(): void
    {
        $page = $this->createBrowser()->request('GET', '/seasons');
        $allTime = $page->filter('[data-page-section="all-time"]');

        self::assertCount(1, $allTime->filter('a[href="/tournaments"]'));
        self::assertCount(1, $allTime->filter('a[href="/records"]'));
    }

    public function testTheLeaderboardCarriesAnAllSeasonsLinkAndNoOverallScope(): void
    {
        $season = $this->season('1', 'Season 1');
        $this->scoredEvent($season, 'Gamesplus 04-07', '2026-07-04', 'Markinu', 25);

        $page = $this->createBrowser()->request('GET', '/season/1');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('a[href="/seasons"]'));
        self::assertStringContainsString('All seasons', $page->filter('[data-page-section="all-seasons"]')->text());

        // No scope selector at all, and nothing offering an "Overall" table.
        self::assertCount(0, $page->filter('[aria-label="Season scope"]'));
        self::assertStringNotContainsString('Overall', $page->text());
    }

    /**
     * Never a fold. `ExpandableTable` marks its container with
     * `data-expandable-table`, so its absence is the assertion.
     */
    public function testTheLeagueStandingsAreNeverCollapsed(): void
    {
        $season = $this->season('1', 'Season 1');
        $event = TournamentFactory::createOne([
            'season' => $season,
            'title' => 'Gamesplus 04-07',
            'heldOn' => new \DateTimeImmutable('2026-07-04'),
        ]);

        foreach (range(1, 18) as $rank) {
            TournamentResultFactory::createOne([
                'tournament' => $event,
                'player' => PlayerFactory::createOne(['name' => sprintf('Blader %02d', $rank)]),
                'rank' => $rank,
                'f1Points' => max(0, 26 - $rank),
                'bonusPoints' => 0,
            ]);
        }

        $page = $this->createBrowser()->request('GET', '/season/1');

        self::assertCount(0, $page->filter('[data-expandable-table]'), 'The league standings are never collapsed.');
        self::assertStringNotContainsString('Show more', $page->text());
        self::assertCount(18, $page->filter('table tbody tr'));
    }

    private function season(string $slug, string $name): Season
    {
        return SeasonFactory::createOne([
            'slug' => $slug,
            'name' => $name,
            'requiresPayment' => false,
        ]);
    }

    private function scoredEvent(Season $season, string $title, string $heldOn, string $winner, int $points): void
    {
        $event = TournamentFactory::createOne([
            'season' => $season,
            'title' => $title,
            'heldOn' => new \DateTimeImmutable($heldOn),
        ]);

        TournamentResultFactory::createOne([
            'tournament' => $event,
            'player' => PlayerFactory::findOrCreate(['name' => $winner]),
            'rank' => 1,
            'f1Points' => $points,
            'bonusPoints' => 0,
        ]);
    }

    /**
     * @return list<string>
     */
    private function cards(Crawler $page): array
    {
        return $page->filter('[data-season]')->each(
            static fn (Crawler $card): string => (string) $card->attr('data-season'),
        );
    }
}
