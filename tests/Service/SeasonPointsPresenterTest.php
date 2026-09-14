<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Season;
use App\Service\SeasonPointsPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The season blocks on a player page, and what the newest one's next result
 * has to score.
 *
 * No database: the presenter is handed rows shaped as
 * `PlayerRepository::getPlayerContributionsBySeason()` returns them, with the
 * `counts` flag already decided. `BestFourteenTest` is where that flag is.
 */
final class SeasonPointsPresenterTest extends TestCase
{
    public function testPastTheCapTheThresholdIsTheLowestCountedScore(): void
    {
        self::assertSame(
            ['threshold' => 1, 'open' => 0],
            SeasonPointsPresenter::nextResult(15, 1),
        );
    }

    public function testAtTheCapTheThresholdIsTheLowestCountedScore(): void
    {
        self::assertSame(
            ['threshold' => 2, 'open' => 0],
            SeasonPointsPresenter::nextResult(14, 2),
        );
    }

    public function testUnderTheCapAnythingCounts(): void
    {
        self::assertSame(
            ['threshold' => null, 'open' => 2],
            SeasonPointsPresenter::nextResult(12, 3),
        );
    }

    /**
     * The worked example: fifteen results totalling 109, the oldest 1-pointer
     * dropped, so the season is 108 — the leaderboard's figure, not 109.
     */
    public function testTheSubtotalAddsCountedResultsOnly(): void
    {
        $season = $this->season('1', 'Season 1');
        $rows = [];

        for ($event = 1; $event <= 14; ++$event) {
            $rows[] = $this->row($season, 'Event '.$event, 14 === $event ? 4 : 8, true);
        }
        $rows[] = $this->row($season, 'Gamebreaker 04 July', 1, false);

        [$block] = new SeasonPointsPresenter()->present($rows, null, $season);

        self::assertSame(108, $block['total']);
        self::assertSame(14, $block['counted']);
        self::assertSame(15, $block['results']);
        self::assertCount(15, $block['events']);
        self::assertFalse($block['events'][14]['counts']);
        self::assertSame(4, $block['next']['threshold'] ?? null);
    }

    /**
     * "Next result" means nothing for a season that is over, so in Overall
     * only the newest season's block says what its next result needs.
     *
     * Season 1's slug is `1`, which PHP would turn into an integer array key —
     * the reason the comparison is not made against the key.
     */
    public function testOnlyTheNewestSeasonIsToldWhatItsNextResultNeeds(): void
    {
        $preseason = $this->season('preseason-1', 'Preseason 1');
        $season = $this->season('1', 'Season 1');

        $blocks = new SeasonPointsPresenter()->present([
            $this->row($season, 'Gamesplus 13-09', 25, true),
            $this->row($preseason, 'Gamebreaker 20-06', 18, true),
        ], null, $season);

        self::assertSame(['1', 'preseason-1'], array_column($blocks, 'slug'));
        self::assertNotNull($blocks[0]['next']);
        self::assertNull($blocks[1]['next']);
    }

    public function testAScopeKeepsItsOwnBlockAndNoOther(): void
    {
        $preseason = $this->season('preseason-1', 'Preseason 1');
        $season = $this->season('1', 'Season 1');

        $blocks = new SeasonPointsPresenter()->present([
            $this->row($season, 'Gamesplus 13-09', 25, true),
            $this->row($preseason, 'Gamebreaker 20-06', 18, true),
        ], $preseason, $season);

        self::assertSame(['preseason-1'], array_column($blocks, 'slug'));
        self::assertNull($blocks[0]['next']);
    }

    private function season(string $slug, string $name): Season
    {
        $season = new Season();
        $season->setSlug($slug);
        $season->setName($name);

        return $season;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Season $season, string $title, int $points, bool $counts): array
    {
        return [
            'tournament_id' => crc32($title),
            'tournament_name' => $title,
            'held_on' => '2026-09-13',
            'f1_points' => $points,
            'bonus_points' => 0,
            'total_points' => $points,
            'season_slug' => $season->getSlug(),
            'season_name' => $season->getName(),
            'counts' => $counts,
        ];
    }
}
