<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Challonge as the test environment sees it.
 *
 * `config/services_test.yaml` hands this to ChallongeFetcher in place of the
 * real HTTP client, so nothing in the suite reaches the network. The slug
 * being asked for decides what comes back — and, as on the real site, the
 * standings table is only rendered when `show_standings=1` was sent.
 */
final class FakeChallonge
{
    public const SLUG = 'fixture1';

    /**
     * A bracket that renders no standings table however it is asked for.
     */
    public const SLUG_WITHOUT_STANDINGS = 'fixture2';

    public const BOUNCED_SLUG = 'bounced';

    public const UNREACHABLE_SLUG = 'offline';

    public const UNKNOWN_SLUG = 'nosuchbracket';

    public static function httpClient(): MockHttpClient
    {
        return new MockHttpClient(
            static fn (string $method, string $url): MockResponse => self::respondTo($url),
        );
    }

    public static function modulePage(bool $withStandings = true): string
    {
        return (string) file_get_contents(sprintf(
            '%s/Fixtures/challonge/module-page%s.html',
            dirname(__DIR__),
            $withStandings ? '' : '-without-standings',
        ));
    }

    private static function respondTo(string $url): MockResponse
    {
        return match (true) {
            self::isFor(self::UNREACHABLE_SLUG, $url) => throw new TransportException(sprintf('Could not resolve host for "%s".', $url)),

            self::isFor(self::BOUNCED_SLUG, $url) => new MockResponse(
                '<html><body>Verifying you are human.</body></html>',
                ['http_code' => 403],
            ),

            self::isFor(self::SLUG, $url) => new MockResponse(
                self::modulePage(str_contains($url, 'show_standings=1')),
            ),

            self::isFor(self::SLUG_WITHOUT_STANDINGS, $url) => new MockResponse(
                self::modulePage(withStandings: false),
            ),

            default => new MockResponse('Not Found', ['http_code' => 404]),
        };
    }

    private static function isFor(string $slug, string $url): bool
    {
        return str_contains($url, '/'.$slug.'/module');
    }
}
