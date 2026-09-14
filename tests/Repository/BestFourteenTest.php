<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Player;
use App\Entity\Season;
use App\Repository\PlayerRepository;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Which result a season's best-14 cap drops, in all three windows at once.
 *
 * The total never depended on it — two tied results are worth the same — but
 * a struck-through row does, and the three queries used to order by points
 * alone, leaving Postgres free to cut a different one of a tie each time.
 * **Among equal scores the most recent result counts and the oldest drops**,
 * and on the same day the later import counts.
 *
 * A tie of two can land the right way by luck — Postgres leaves equal keys in
 * whatever order its sort happens to — so the first test ties twenty results
 * inserted in a scrambled order, where only the rule drops exactly the six
 * oldest.
 *
 * This is also `getLeagueLeaderboard()`'s first coverage.
 */
#[ResetDatabase]
final class BestFourteenTest extends ServiceTestCase
{
    public function testEveryTiedResultPastTheCapIsAnOlderOne(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Belti']);
        $season = SeasonFactory::createOne(['slug' => '1', 'name' => 'Season 1', 'requiresPayment' => false]);

        foreach ([7, 19, 2, 14, 11, 5, 17, 1, 9, 20, 4, 13, 16, 3, 10, 18, 6, 12, 15, 8] as $day) {
            $this->scored($blader, $season, sprintf('Gamesplus %02d-08', $day), sprintf('2026-08-%02d', $day), 1);
        }

        $id = (int) $blader->getId();

        for ($request = 1; $request <= 5; ++$request) {
            self::assertSame(
                ['Gamesplus 06-08', 'Gamesplus 05-08', 'Gamesplus 04-08', 'Gamesplus 03-08', 'Gamesplus 02-08', 'Gamesplus 01-08'],
                $this->dropped($this->players()->getPlayerContributionsBySeason($id)),
            );

            $contributing = array_column($this->players()->getPlayerContributingTournaments($id, $season->getSlug()), 'held_on');

            self::assertCount(14, $contributing);
            self::assertSame('2026-08-07', min($contributing));
            self::assertSame(14, $this->leaderboardTotal($blader, $season));
        }
    }

    public function testTheOlderOfTwoTiedResultsIsTheOneDropped(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Belti']);
        $season = SeasonFactory::createOne(['slug' => '1', 'name' => 'Season 1', 'requiresPayment' => false]);

        $this->scored($blader, $season, 'Gamebreaker 04 July', '2026-07-04', 1);

        for ($event = 1; $event <= 13; ++$event) {
            $this->scored($blader, $season, sprintf('Gamesplus %02d', $event), sprintf('2026-08-%02d', $event), 10 + $event);
        }

        $this->scored($blader, $season, 'Gamebreaker 15-08', '2026-08-15', 1);

        $this->assertDropped('Gamebreaker 04 July', $blader, $season);
    }

    public function testOnTheSameDayTheEarlierImportIsTheOneDropped(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Belti']);
        $season = SeasonFactory::createOne(['slug' => '1', 'name' => 'Season 1', 'requiresPayment' => false]);

        $this->scored($blader, $season, 'Morning session', '2026-07-04', 1);

        for ($event = 1; $event <= 13; ++$event) {
            $this->scored($blader, $season, sprintf('Gamesplus %02d', $event), sprintf('2026-08-%02d', $event), 10 + $event);
        }

        $this->scored($blader, $season, 'Evening session', '2026-07-04', 1);

        $this->assertDropped('Morning session', $blader, $season);
    }

    public function testFourteenOrFewerResultsAllCount(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Evilbeys']);
        $season = SeasonFactory::createOne(['slug' => '1', 'name' => 'Season 1', 'requiresPayment' => false]);

        for ($event = 1; $event <= 14; ++$event) {
            $this->scored($blader, $season, sprintf('Gamesplus %02d', $event), sprintf('2026-08-%02d', $event), 5);
        }

        $rows = $this->players()->getPlayerContributionsBySeason((int) $blader->getId());

        self::assertCount(14, $rows);
        self::assertSame([], array_values(array_filter($rows, static fn (array $row): bool => !$row['counts'])));
        self::assertSame(70, $this->leaderboardTotal($blader, $season));
    }

    /**
     * The same result, out of every window, on every request: the player
     * page flags it, the contributing list leaves it out and the leaderboard
     * does not add it.
     */
    private function assertDropped(string $title, Player $blader, Season $season): void
    {
        $id = (int) $blader->getId();

        for ($request = 1; $request <= 5; ++$request) {
            $rows = $this->players()->getPlayerContributionsBySeason($id);

            self::assertCount(15, $rows, 'Every result is listed, counted or not.');
            self::assertSame([$title], $this->dropped($rows));

            $contributing = array_column($this->players()->getPlayerContributingTournaments($id, $season->getSlug()), 'tournament_name');

            self::assertCount(14, $contributing);
            self::assertNotContains($title, $contributing);

            $counted = array_sum(array_map(
                static fn (array $row): int => (int) $row['total_points'],
                array_filter($rows, static fn (array $row): bool => (bool) $row['counts']),
            ));

            self::assertSame($counted, $this->leaderboardTotal($blader, $season));
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function dropped(array $rows): array
    {
        return array_values(array_map(
            static fn (array $row): string => (string) $row['tournament_name'],
            array_filter($rows, static fn (array $row): bool => !$row['counts']),
        ));
    }

    private function leaderboardTotal(Player $blader, Season $season): int
    {
        foreach ($this->players()->getLeagueLeaderboard($season->getSlug()) as $row) {
            if ((int) $row['id'] === $blader->getId()) {
                return (int) $row['total'];
            }
        }

        self::fail(sprintf('%s is not on the leaderboard.', $blader->getName()));
    }

    private function scored(Player $blader, Season $season, string $title, string $heldOn, int $f1Points): void
    {
        TournamentResultFactory::createOne([
            'tournament' => TournamentFactory::createOne([
                'season' => $season,
                'title' => $title,
                'heldOn' => new \DateTimeImmutable($heldOn),
            ]),
            'player' => $blader,
            'rank' => 1,
            'f1Points' => $f1Points,
            'bonusPoints' => 0,
        ]);
    }

    private function players(): PlayerRepository
    {
        return self::getContainer()->get(PlayerRepository::class);
    }
}
