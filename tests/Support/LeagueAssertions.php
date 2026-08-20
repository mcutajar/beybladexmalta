<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Season;
use App\Entity\Tournament;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonRegistrationFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\SeasonStory;

/**
 * Assertions phrased in league terms rather than in factory criteria, so a
 * test body states what should be true of the league instead of how to look
 * it up.
 */
trait LeagueAssertions
{
    /**
     * Points awarded to each of the ten finishing ranks, best finish first.
     */
    protected const F1_POINTS_BY_RANK = [25, 20, 15, 12, 10, 8, 6, 4, 2, 1];

    protected static function assertPlayerHasPaid(
        string $name,
        ?Season $season = null,
    ): void {
        SeasonRegistrationFactory::assert()->exists([
            'player' => PlayerFactory::find(['name' => $name]),
            'season' => $season ?? SeasonStory::paymentSeason(),
            'paid' => true,
        ]);
    }

    protected static function assertPlayerHasNotPaid(
        string $name,
        ?Season $season = null,
    ): void {
        SeasonRegistrationFactory::assert()->exists([
            'player' => PlayerFactory::find(['name' => $name]),
            'season' => $season ?? SeasonStory::paymentSeason(),
            'paid' => false,
        ]);
    }

    protected static function assertNothingWasRegistered(): void
    {
        PlayerFactory::assert()->empty();
        SeasonRegistrationFactory::assert()->empty();
    }

    protected static function assertNothingWasImported(): void
    {
        TournamentFactory::assert()->empty();
        TournamentResultFactory::assert()->empty();
        PlayerFactory::assert()->empty();
    }

    /**
     * Asserts only the facets that are named, so a test can speak about the
     * bonus on one rank without restating that rank's whole scoreline.
     */
    protected static function assertResultAtRank(
        Tournament $tournament,
        int $rank,
        ?string $player = null,
        ?int $f1Points = null,
        ?int $bonusPoints = null,
        ?int $totalPoints = null,
    ): void {
        $result = TournamentResultFactory::find([
            'tournament' => $tournament,
            'rank' => $rank,
        ]);

        if (null !== $player) {
            self::assertSame(
                $player,
                $result->getPlayer()->getName(),
                sprintf('Rank %d should belong to %s.', $rank, $player),
            );
        }

        if (null !== $f1Points) {
            self::assertSame(
                $f1Points,
                $result->getF1Points(),
                sprintf('Rank %d scored the wrong F1 tier.', $rank),
            );
        }

        if (null !== $bonusPoints) {
            self::assertSame(
                $bonusPoints,
                $result->getBonusPoints(),
                sprintf('Rank %d carries the wrong bonus.', $rank),
            );
        }

        if (null !== $totalPoints) {
            self::assertSame(
                $totalPoints,
                $result->getTotalPoints(),
                sprintf('Rank %d totalled incorrectly.', $rank),
            );
        }
    }

    /**
     * @param list<string> $placements best finish first
     */
    protected static function assertPlacementsScoredInOrder(
        Tournament $tournament,
        array $placements,
    ): void {
        foreach ($placements as $index => $player) {
            self::assertResultAtRank(
                $tournament,
                rank: $index + 1,
                player: $player,
                f1Points: self::F1_POINTS_BY_RANK[$index],
            );
        }
    }

    protected static function findTournament(string $title): Tournament
    {
        return TournamentFactory::find(['title' => $title]);
    }
}
