<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Season;

/**
 * One blader's scoring events, filed under the season each scored in, with
 * the results that season's best-14 cap drops marked rather than hidden.
 *
 * **No total across seasons, here or anywhere the page can reach.** The
 * subtotal belongs to its season and travels with it — a figure orphaned from
 * its season is exactly the cross-season total the scope contract forbids.
 *
 * The subtotal adds **counted results only**, so it is the figure the
 * leaderboard shows for the same blader. Every result is listed all the same:
 * a dropped one used to vanish from the page with nothing to say it had been
 * played, which one it was, or why.
 *
 * Only the league's newest season says what its next result needs. "What the
 * next result has to score" means nothing for a season that is over, and `Season` has no
 * closed flag to say which ones are — so the newest season is taken to be the
 * one still running.
 *
 * @phpstan-type NextResult array{threshold: ?int, open: int}
 * @phpstan-type PointsBlock array{slug: string, name: string, total: int, counted: int, results: int, events: list<array<string, mixed>>, next: ?NextResult}
 */
final class SeasonPointsPresenter
{
    /** How many of a season's results count towards its total. */
    public const int CAP = 14;

    /**
     * @param list<array<string, mixed>> $rows   as `PlayerRepository::getPlayerContributionsBySeason()` returns them
     * @param ?Season                    $scope  the season the page is narrowed to, or null for Overall
     * @param ?Season                    $newest the league's most recent season, the only one told what its next result needs
     *
     * @return list<PointsBlock>
     */
    public function present(array $rows, ?Season $scope, ?Season $newest): array
    {
        $blocks = [];

        foreach ($rows as $row) {
            $slug = (string) $row['season_slug'];

            if (null !== $scope && $slug !== $scope->getSlug()) {
                continue;
            }

            $blocks[$slug] ??= [
                'slug' => $slug,
                'name' => (string) $row['season_name'],
                'total' => 0,
                'counted' => 0,
                'results' => 0,
                'events' => [],
                'next' => null,
                'lowest' => null,
            ];

            $counts = (bool) $row['counts'];
            $points = (int) $row['total_points'];

            ++$blocks[$slug]['results'];

            if ($counts) {
                ++$blocks[$slug]['counted'];
                $blocks[$slug]['total'] += $points;
                $blocks[$slug]['lowest'] = min($blocks[$slug]['lowest'] ?? $points, $points);
            }

            $blocks[$slug]['events'][] = ['counts' => $counts] + $row;
        }

        $presented = [];

        foreach ($blocks as $block) {
            $lowest = $block['lowest'];
            unset($block['lowest']);

            // Compared on the block's own slug rather than its key: PHP turns
            // a numeric string key into an integer, and Season 1's slug is `1`.
            if (null !== $newest && $block['slug'] === $newest->getSlug()) {
                $block['next'] = self::nextResult($block['results'], $lowest);
            }

            $presented[] = $block;
        }

        return $presented;
    }

    /**
     * What the next event has to score to count.
     *
     * Under the cap, anything counts, and `open` says how many more will. At
     * or past it, the threshold is the
     * lowest counted score — and matching it is enough, because among equal
     * scores the most recent result counts and the oldest drops. If that tie
     * rule ever changed, "or more" would stop being true.
     *
     * @param ?int $lowest the lowest counted score, null when nothing counts
     *
     * @return NextResult
     */
    public static function nextResult(int $results, ?int $lowest): array
    {
        return [
            'threshold' => $results >= self::CAP ? $lowest : null,
            'open' => max(0, self::CAP - $results),
        ];
    }
}
