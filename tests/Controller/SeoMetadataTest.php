<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Support\PageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The head metadata every page carries.
 *
 * Several routes answer to the same page here — the leaderboard has four URLs
 * and the homepage two — which is the thing a crawler reads as a duplicate
 * site. These assert that each page names one URL as its own, and that the
 * pages which should not be indexed say so.
 */
final class SeoMetadataTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * All four leaderboard URLs, agreeing on one.
     *
     * Asserted as "identical, and it is the explicit form" rather than against
     * a written-out URL, so the test does not restate the configured origin
     * back to itself.
     */
    public function testEveryLeaderboardAliasCanonicalisesOntoOneUrl(): void
    {
        SeasonFactory::createOne(['name' => 'Preseason 1', 'slug' => 'preseason-1']);

        $canonicals = array_map(
            fn (string $path): string => $this->canonical($path),
            ['/season', '/season/preseason-1', '/seasons/preseason-1', '/preseason'],
        );

        self::assertCount(1, array_unique($canonicals), 'Expected the leaderboard aliases to agree on one canonical.');
        self::assertStringEndsWith('/seasons/preseason-1', $canonicals[0]);
    }

    public function testTheHomepageAliasCanonicalisesOntoTheHomepage(): void
    {
        $home = $this->canonical('/');

        self::assertSame($home, $this->canonical('/v2'));
        self::assertStringEndsWith('/', $home);
    }

    public function testTheHomepageIsIndexed(): void
    {
        self::assertSame('index, follow', $this->robots('/'));
    }

    /**
     * The superseded drafts stay reachable — their URLs have been shared — and
     * stay out of the index, because they are the homepage again with slightly
     * different words.
     */
    public function testASupersededProposalIsNotIndexed(): void
    {
        self::assertSame('noindex, follow', $this->robots('/v1'));
        self::assertSame('noindex, follow', $this->robots('/v0'));
    }

    public function testAnAdminToolIsNotIndexed(): void
    {
        self::assertSame('noindex, nofollow', $this->robots('/admin/merge-player'));
        self::assertSame('noindex, nofollow', $this->robots('/admin/import'));
        self::assertSame('noindex, nofollow', $this->robots('/admin/payments'));
    }

    /**
     * A `?season=` page is a different page rather than a filtered view of
     * one, so it is its own canonical and keeps the scope.
     */
    public function testASeasonScopedPageKeepsItsScopeInTheCanonical(): void
    {
        SeasonFactory::createOne(['slug' => 'scoped-season']);

        self::assertStringEndsWith('/records', $this->canonical('/records'));
        self::assertStringEndsWith('/records?season=scoped-season', $this->canonical('/records?season=scoped-season'));
        self::assertStringEndsWith('/tournaments?season=scoped-season', $this->canonical('/tournaments?season=scoped-season'));
    }

    /**
     * One description per page, not three that drift apart: the `og:` tags
     * re-render the same blocks rather than restating them.
     */
    public function testTheOpenGraphTagsMirrorTheTitleAndDescription(): void
    {
        $page = $this->visit('/');

        self::assertSame($page->filter('title')->text(), $this->attribute($page, 'meta[property="og:title"]', 'content'));
        self::assertSame(
            $this->attribute($page, 'meta[name="description"]', 'content'),
            $this->attribute($page, 'meta[property="og:description"]', 'content'),
        );
        self::assertSame(
            $this->attribute($page, 'link[rel="canonical"]', 'href'),
            $this->attribute($page, 'meta[property="og:url"]', 'content'),
        );
    }

    public function testTheDescriptionSaysWhatTheLeagueIs(): void
    {
        self::assertStringContainsString('Beyblade X', $this->attribute($this->visit('/'), 'meta[name="description"]', 'content'));
    }

    /**
     * `block()` hands back output Twig has already escaped, so re-rendering it
     * into `og:title` has to skip the second pass. A blader called `Bey &
     * Blade "X"` is what tells the two apart: double-escaped, the share card
     * reads `Bey &amp; Blade`.
     */
    public function testAnAmpersandInATitleIsEscapedExactlyOnce(): void
    {
        PlayerFactory::createOne(['name' => 'Bey & Blade "X"', 'slug' => 'bey-and-blade']);

        $page = $this->visit('/player/bey-and-blade');

        self::assertStringContainsString('Bey & Blade "X"', $page->filter('title')->text());
        self::assertSame(
            $page->filter('title')->text(),
            $this->attribute($page, 'meta[property="og:title"]', 'content'),
        );
        self::assertStringNotContainsString('&amp;amp;', $this->html('/player/bey-and-blade'));
    }

    private function canonical(string $path): string
    {
        return $this->attribute($this->visit($path), 'link[rel="canonical"]', 'href');
    }

    private function robots(string $path): string
    {
        return $this->attribute($this->visit($path), 'meta[name="robots"]', 'content');
    }

    private function attribute(Crawler $page, string $selector, string $attribute): string
    {
        $node = $page->filter($selector);

        self::assertCount(1, $node, sprintf('Expected exactly one "%s".', $selector));

        return (string) $node->attr($attribute);
    }

    private function visit(string $path): Crawler
    {
        $page = $this->createBrowser()->request('GET', $path);

        self::assertResponseIsSuccessful(sprintf('Expected %s to render.', $path));

        return $page;
    }

    private function html(string $path): string
    {
        $browser = $this->createBrowser();
        $browser->request('GET', $path);

        return (string) $browser->getResponse()->getContent();
    }
}
