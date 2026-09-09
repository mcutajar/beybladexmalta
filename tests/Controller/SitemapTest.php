<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Support\PageTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The sitemap.
 *
 * It exists to get the site discovered at all — it had no sitemap and no
 * inbound links, which between them are the only two ways Google finds a new
 * site. So what these assert is membership and absence rather than a total:
 * the league gains tournaments twice a week, and a test that wrote down how
 * many URLs there are would fail every ordinary Tuesday.
 */
final class SitemapTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    private const string SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    public function testItServesXml(): void
    {
        $browser = $this->createBrowser();
        $browser->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
    }

    public function testItListsTheLandingPages(): void
    {
        $paths = array_keys($this->entries());

        self::assertContains('/', $paths);
        self::assertContains('/seasons', $paths);
        self::assertContains('/tournaments', $paths);
        self::assertContains('/records', $paths);
        self::assertContains('/registrations', $paths);
    }

    /**
     * Every URL absolute, on one origin, over HTTPS.
     *
     * The origin is not read from the configured value and compared with
     * itself — the assertion is that the file speaks with one voice and that
     * the voice is `https`. Cloudflare terminates TLS and speaks plain HTTP to
     * the container, so a sitemap built from the request would fail this.
     */
    public function testEveryUrlIsAbsoluteAndOnOneSecureOrigin(): void
    {
        SeasonFactory::createOne(['slug' => 'season-under-test']);
        TournamentFactory::createOne();

        $locations = $this->locations();
        self::assertNotEmpty($locations);

        $origins = [];

        foreach ($locations as $location) {
            $parts = parse_url($location);

            self::assertIsArray($parts, sprintf('Expected %s to be a URL.', $location));
            self::assertSame('https', $parts['scheme'] ?? null, sprintf('Expected %s to be https.', $location));
            self::assertArrayHasKey('host', $parts);

            $origins[$parts['host']] = true;
        }

        self::assertCount(1, $origins, 'Expected every sitemap URL to share one origin.');
    }

    /**
     * A season is listed once, at the URL that names it.
     *
     * `/season/{slug}` carries a default, so the router collapses the
     * preseason to a bare `/season`; `/seasons/{slug}` is the only form that
     * names every season. The aliases stay out, and `SeoMetadataTest` asserts
     * the matching `rel=canonical` from the other side.
     */
    public function testItNamesASeasonByItsExplicitUrl(): void
    {
        SeasonFactory::createOne(['slug' => 'preseason-1']);

        $paths = array_keys($this->entries());

        self::assertContains('/seasons/preseason-1', $paths);
        self::assertNotContains('/season/preseason-1', $paths);
        self::assertNotContains('/season', $paths);
        self::assertNotContains('/preseason', $paths);
    }

    public function testItDatesATournamentByWhenItWasHeld(): void
    {
        $tournament = TournamentFactory::createOne([
            'heldOn' => new \DateTimeImmutable('2026-08-30'),
        ]);

        $entries = $this->entries();
        $path = sprintf('/tournament/%d', $tournament->getId());

        self::assertArrayHasKey($path, $entries);
        self::assertSame('2026-08-30', $entries[$path]);
    }

    /**
     * A season's date is the last event that scored into it, because a season
     * carries no date of its own.
     */
    public function testItDatesASeasonByItsLatestEvent(): void
    {
        $season = SeasonFactory::createOne(['slug' => 'dated-season']);

        TournamentFactory::createOne(['heldOn' => new \DateTimeImmutable('2026-07-04'), 'season' => $season]);
        TournamentFactory::createOne(['heldOn' => new \DateTimeImmutable('2026-08-30'), 'season' => $season]);
        TournamentFactory::createOne(['heldOn' => new \DateTimeImmutable('2026-08-02'), 'season' => $season]);

        self::assertSame('2026-08-30', $this->entries()['/seasons/dated-season'] ?? null);
    }

    /**
     * A season nothing has been held in gets no date rather than today's. A
     * sitemap that stamps today on everything teaches the crawler to ignore
     * the field on the pages where it was true.
     */
    public function testASeasonWithNoEventsCarriesNoDate(): void
    {
        SeasonFactory::createOne(['slug' => 'not-started']);

        $entries = $this->entries();

        self::assertArrayHasKey('/seasons/not-started', $entries);
        self::assertNull($entries['/seasons/not-started']);
    }

    public function testItListsABladerProfile(): void
    {
        PlayerFactory::createOne(['name' => 'Ziggy', 'slug' => 'ziggy']);

        self::assertContains('/player/ziggy', array_keys($this->entries()));
    }

    /**
     * The duplicate URLs stay out. Each of these renders a page the sitemap
     * already names once, and listing an alias asks the crawler to pick a
     * canonical for us.
     */
    public function testItLeavesOutTheDuplicateAndPrivateUrls(): void
    {
        $paths = array_keys($this->entries());

        self::assertNotContains('/v0', $paths);
        self::assertNotContains('/v1', $paths);
        self::assertNotContains('/v2', $paths);

        foreach ($paths as $path) {
            self::assertStringStartsNotWith('/admin/', $path, 'Admin tools are not content.');
        }
    }

    /**
     * `robots.txt` is a static file and Caddy serves it, so no request in this
     * suite can reach it. It also names the production origin outright, which
     * the test environment's `SITE_URL` deliberately is not — comparing the
     * two would only assert that a constant equals itself.
     *
     * The half that can actually drift is the path, so that is what is
     * checked, and it is checked by asking the application to answer on it.
     */
    public function testRobotsTxtPointsAtASitemapThisApplicationServes(): void
    {
        $robots = file_get_contents(\dirname(__DIR__, 2).'/public/robots.txt');

        self::assertIsString($robots);
        self::assertStringContainsString('Disallow: /admin/', $robots);
        self::assertSame(1, preg_match('/^Sitemap: (\S+)$/m', $robots, $named), 'Expected robots.txt to name a sitemap.');

        $path = (string) parse_url($named[1], \PHP_URL_PATH);

        $this->createBrowser()->request('GET', $path);

        self::assertResponseIsSuccessful(sprintf('robots.txt points at %s, which this application does not answer.', $path));
    }

    /**
     * The canonical origin must not be committed.
     *
     * `.env` ships in the image and in every fork of a codebase that is
     * licensed to be forked. A real host here would have a fork's canonical
     * tags claiming its pages belong to the original site — silently, and only
     * visibly in somebody else's search results.
     */
    public function testTheCanonicalOriginIsNotCommittedToTheDefaultEnvFile(): void
    {
        $env = file_get_contents(\dirname(__DIR__, 2).'/.env');

        self::assertIsString($env);
        self::assertMatchesRegularExpression(
            '/^SITE_URL=\s*$/m',
            $env,
            'SITE_URL must stay empty in .env — set it in .env.dev, .env.test, or the deploy host\'s .env.local.',
        );
    }

    /**
     * @return list<string>
     */
    private function locations(): array
    {
        return array_map(
            static fn (array $entry): string => $entry['loc'],
            $this->parse(),
        );
    }

    /**
     * Path to its `lastmod`, which is null for anything the database cannot
     * date.
     *
     * @return array<string, string|null>
     */
    private function entries(): array
    {
        $entries = [];

        foreach ($this->parse() as $entry) {
            $entries[(string) parse_url($entry['loc'], PHP_URL_PATH)] = $entry['lastmod'];
        }

        return $entries;
    }

    /**
     * Parsing through SimpleXML rather than a regex is also the assertion that
     * the document is well-formed: a malformed one throws here.
     *
     * @return list<array{loc: string, lastmod: string|null}>
     */
    private function parse(): array
    {
        $browser = $this->createBrowser();
        $browser->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();

        $document = new \SimpleXMLElement((string) $browser->getResponse()->getContent());
        $entries = [];

        foreach ($document->children(self::SITEMAP_NS)->url as $url) {
            $entries[] = [
                'loc' => (string) $url->loc,
                'lastmod' => isset($url->lastmod) ? (string) $url->lastmod : null,
            ];
        }

        return $entries;
    }
}
