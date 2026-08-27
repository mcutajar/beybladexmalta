<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Player;
use App\Entity\Season;
use App\Entity\Tournament;
use App\Entity\TournamentTeam;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonRegistrationFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Factory\TournamentTeamFactory;
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
        self::assertNoEventWasImported();

        PlayerFactory::assert()->empty();
    }

    /**
     * No event, and no result or entrant belonging to one.
     *
     * Said apart from the bladers because a bracket import runs against a
     * league that already has some: the question there is whether the event
     * was written, and separately whether anybody was invented.
     */
    protected static function assertNoEventWasImported(): void
    {
        TournamentFactory::assert()->empty();
        TournamentResultFactory::assert()->empty();
        TournamentTeamFactory::assert()->empty();
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

    /**
     * One entrant of a team event: where it finished, and who the league says
     * was in it.
     *
     * The bladers are compared as a set rather than in order — a roster is a
     * pairing, and which half was typed first says nothing.
     *
     * @param ?list<string> $bladers null to say nothing about the roster
     */
    protected static function assertTeamAtRank(
        Tournament $tournament,
        int $rank,
        string $name,
        ?array $bladers = null,
    ): void {
        $team = TournamentTeamFactory::find([
            'tournament' => $tournament,
            'rank' => $rank,
        ]);

        self::assertSame(
            $name,
            $team->getName(),
            sprintf('Rank %d should be "%s".', $rank, $name),
        );

        if (null === $bladers) {
            return;
        }

        $roster = array_map(
            static fn (Player $blader): string => $blader->getName(),
            $team->getBladers(),
        );

        sort($roster);
        sort($bladers);

        self::assertSame(
            $bladers,
            $roster,
            sprintf('"%s" has the wrong bladers in it.', $name),
        );
    }

    /**
     * A team the league has on record with nobody in it — a finishing position
     * that belongs to somebody, kept until they say so.
     */
    protected static function assertTeamIsUnclaimed(
        Tournament $tournament,
        string $name,
    ): void {
        $team = self::teamCalled($tournament, $name);

        self::assertFalse(
            $team->isClaimed(),
            sprintf('"%s" should have nobody in it.', $name),
        );
    }

    protected static function assertNoTeamCalled(
        Tournament $tournament,
        string $name,
    ): void {
        self::assertNull(
            TournamentTeamFactory::repository()->findOneBy([
                'tournament' => $tournament,
                'name' => $name,
            ]),
            sprintf('"%s" is not an entrant and should not be on record.', $name),
        );
    }

    protected static function teamCalled(
        Tournament $tournament,
        string $name,
    ): TournamentTeam {
        return TournamentTeamFactory::find([
            'tournament' => $tournament,
            'name' => $name,
        ]);
    }

    protected static function findTournament(string $title): Tournament
    {
        return TournamentFactory::find(['title' => $title]);
    }
}
