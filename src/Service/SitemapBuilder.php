<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\SitemapUrl;
use App\Repository\PlayerRepository;
use App\Repository\SeasonRepository;
use App\Repository\TournamentRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The URL list behind `/sitemap.xml`.
 *
 * The league had no sitemap and no inbound links, which between them are the
 * only two ways Google finds a new site at all. That is why this exists: not
 * to rank the pages it lists, but to make them discoverable in the first
 * place.
 *
 * **One URL per thing, and the aliases are left out.** Several routes answer
 * to the same page — `/seasons/{slug}` and `/preseason` are the leaderboard
 * again, `/v2` is the homepage again, and `/v1` and `/v0` are superseded
 * drafts of it. Listing an alias asks the crawler to pick a canonical for us
 * and splits whatever authority the page earns across the copies, so the
 * sitemap names the one URL each page should be known by and the templates
 * carry a matching `rel=canonical`.
 *
 * Admin routes are absent for the same reason `robots.txt` disallows them:
 * they are passphrase-gated tools, not content.
 */
final readonly class SitemapBuilder
{
    /**
     * The pages that exist regardless of what is in the database, in the order
     * a reader would meet them.
     */
    private const array LANDING_ROUTES = [
        'app_league_proposal_v2',
        'seasons_index',
        'tournament_archive',
        'records_board',
        'league_registrations',
    ];

    public function __construct(
        private SeasonRepository $seasons,
        private TournamentRepository $tournaments,
        private PlayerRepository $players,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @return list<SitemapUrl>
     */
    public function build(): array
    {
        $events = $this->tournaments->everyEventDated();

        return [
            ...$this->landingPages(),
            ...$this->seasonPages($events),
            ...$this->tournamentPages($events),
            ...$this->bladerPages(),
        ];
    }

    /**
     * @return list<SitemapUrl>
     */
    private function landingPages(): array
    {
        $pages = [];

        foreach (self::LANDING_ROUTES as $route) {
            $pages[] = new SitemapUrl($this->urls->generate($route));
        }

        return $pages;
    }

    /**
     * A leaderboard is dated by the last event that scored into it.
     *
     * A season carries no date of its own — the tournaments hold them — so
     * this is the only honest answer to "when did this page last change", and
     * a season that has held nothing yet gets no date rather than today's.
     *
     * @param list<array{id: int, heldOn: \DateTimeImmutable, seasonSlug: string|null}> $events
     *
     * @return list<SitemapUrl>
     */
    private function seasonPages(array $events): array
    {
        $latest = [];

        foreach ($events as $event) {
            $slug = $event['seasonSlug'];

            if (null === $slug) {
                continue;
            }

            if (!isset($latest[$slug]) || $event['heldOn'] > $latest[$slug]) {
                $latest[$slug] = $event['heldOn'];
            }
        }

        $pages = [];

        foreach ($this->seasons->ordered() as $season) {
            $slug = $season->getSlug();

            $pages[] = new SitemapUrl(
                $this->urls->generate('season_leaderboard_2', ['slug' => $slug]),
                $latest[$slug] ?? null,
            );
        }

        return $pages;
    }

    /**
     * @param list<array{id: int, heldOn: \DateTimeImmutable, seasonSlug: string|null}> $events
     *
     * @return list<SitemapUrl>
     */
    private function tournamentPages(array $events): array
    {
        $pages = [];

        foreach ($events as $event) {
            $pages[] = new SitemapUrl(
                $this->urls->generate('tournament_page', ['id' => $event['id']]),
                $event['heldOn'],
            );
        }

        return $pages;
    }

    /**
     * A profile carries no date. Deriving one would mean a query per blader
     * for their latest result, and `SitemapUrl` would rather say nothing.
     *
     * @return list<SitemapUrl>
     */
    private function bladerPages(): array
    {
        $pages = [];

        foreach ($this->players->everyBladerSlug() as $slug) {
            $pages[] = new SitemapUrl($this->urls->generate('player_page', ['slug' => $slug]));
        }

        return $pages;
    }
}
