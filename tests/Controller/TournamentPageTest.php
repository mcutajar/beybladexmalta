<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tournament;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Factory\TournamentTeamFactory;
use App\Tests\Factory\TournamentTeamMemberFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\PageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * What a team event's page has to say that no other page does.
 *
 * An unclaimed team has no placement, so it appears nowhere in the standings
 * and would leave no trace on the page at all — which is exactly how the
 * eleventh-place team on 11 July vanished in the first place.
 */
#[WithStory(SeasonStory::class)]
final class TournamentPageTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    public function testATeamEventNamesAnUnclaimedTeamAtItsRank(): void
    {
        $event = $this->teamEvent();

        TournamentTeamFactory::createOne([
            'tournament' => $event,
            'name' => 'melhina',
            'rank' => 11,
        ]);

        $row = $this->tableRows($this->render($event), table: 0);

        self::assertCount(1, $row);
        self::assertSame(['11', 'melhina', 'Unclaimed'], $row[0]);
    }

    /**
     * Two bladers share a finishing position in a team event, so the standings
     * table has to read the rank off the result rather than count the rows.
     */
    public function testTwoBladersOfOneTeamShowTheSameRank(): void
    {
        $event = $this->teamEvent();

        $team = TournamentTeamFactory::createOne([
            'tournament' => $event,
            'name' => 'the bakers',
            'rank' => 6,
        ]);

        foreach (['Belti', 'Amanda'] as $name) {
            $blader = PlayerFactory::createOne(['name' => $name]);

            TournamentTeamMemberFactory::createOne(['team' => $team, 'player' => $blader]);
            TournamentResultFactory::createOne([
                'tournament' => $event,
                'player' => $blader,
                'rank' => 6,
                'f1Points' => 8,
                'bonusPoints' => 0,
            ]);
        }

        $standings = $this->tableRows($this->render($event), table: 1);

        self::assertSame(['6', '6'], array_column($standings, 0));
        self::assertSame(['Amanda', 'Belti'], array_column($standings, 1));
    }

    /**
     * Every other event has no teams, and the card has nothing to say.
     */
    public function testAnOrdinaryEventShowsNoTeamsTable(): void
    {
        $event = TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => 'Gamesplus 08-02',
        ]);

        TournamentResultFactory::createOne([
            'tournament' => $event,
            'player' => PlayerFactory::createOne(['name' => 'Derius']),
            'rank' => 1,
            'f1Points' => 25,
            'bonusPoints' => 10,
        ]);

        self::assertCount(1, $this->render($event)->filter('table'));
    }

    private function teamEvent(): Tournament
    {
        return TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => '11 July Gamebreaker 2v2',
        ]);
    }

    private function render(Tournament $event): Crawler
    {
        $crawler = $this->createBrowser()->request(
            'GET',
            sprintf('/season/paid-season/tournament/%d', $event->getId()),
        );

        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /**
     * One table's body, cell by cell and whitespace collapsed.
     *
     * @return list<list<string>>
     */
    private function tableRows(Crawler $page, int $table): array
    {
        return $page->filter('table')->eq($table)->filter('tbody tr')->each(
            static fn (Crawler $row): array => $row->filter('td')->each(
                static fn (Crawler $cell): string => trim((string) preg_replace('/\s+/', ' ', $cell->text())),
            ),
        );
    }
}
