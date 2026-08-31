<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\ChallongeRecord;
use App\Dto\ChallongeStageKind;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;
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

        $page = $this->render($event);
        $row = $this->tableRows($page, table: 0);

        self::assertCount(1, $row);
        self::assertSame(['11', 'melhina', 'Unclaimed'], $row[0]);
        self::assertSelectorTextContains('body', 'Match archive not available');
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

    public function testATournamentWithoutAnArchiveKeepsItsPointsAndShowsAnEmptyArchiveState(): void
    {
        $event = TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => 'Results before archive',
        ]);
        TournamentResultFactory::createOne([
            'tournament' => $event,
            'player' => PlayerFactory::createOne(['name' => 'Derius']),
            'rank' => 1,
            'f1Points' => 25,
            'bonusPoints' => 10,
        ]);

        $page = $this->render($event);

        self::assertSelectorTextContains('body', 'Match archive not available');
        self::assertSame(['1', 'Derius +10', '25', '35 pts'], $this->tableRows($page, table: 0)[0]);
    }

    public function testTheGamesplusAcceptanceFixtureShowsEveryMatchAndCompleteCutPaths(): void
    {
        $event = $this->archivedGamesplusEvent();
        $page = $this->render($event);

        $representedMatches = $page->filter('[data-match-id], [data-cut-match-id]')->each(
            static fn (Crawler $match): string => (string) ($match->attr('data-match-id') ?? $match->attr('data-cut-match-id')),
        );
        self::assertCount(63, array_unique($representedMatches));
        self::assertSame(
            ['league-points', 'top-cut', 'swiss-standings', 'swiss-rounds'],
            $page->filter('[data-page-section]')->each(static fn (Crawler $section): ?string => $section->attr('data-page-section')),
        );
        self::assertStringNotContainsString('(invitation pending)', $page->text());
        self::assertCount(5, $page->filter('[data-swiss-round]'));
        self::assertCount(2, $page->filter('[data-expandable-table][data-initial-rows="5"]'));
        self::assertCount(2, $page->filter('[data-expandable-toggle][aria-expanded="false"]'));
        self::assertSelectorTextContains('[data-page-section="league-points"]', 'League points');
        self::assertSelectorTextContains('[data-page-section="league-points"]', '+10');
        self::assertStringNotContainsString('+10', $page->filter('[data-page-section="top-cut"]')->text());
        self::assertSame(['11', '11', '11', '11', '11'], $page->filter('[data-swiss-round]')->each(
            static fn (Crawler $round): string => (string) $round->filter('[data-match-id]')->count(),
        ));
        self::assertCount(0, $page->filter('[data-swiss-round][open]'));
        self::assertGreaterThan(0, $page->filter('[data-consolation]')->count());
        self::assertSelectorTextContains('body', '3P');
        self::assertCount(8, $page->filter('[data-cut-path]'));
        self::assertSame(['3', '3', '3', '3', '1', '1', '1', '1'], $page->filter('tr[data-cut-path]')->each(
            static fn (Crawler $path): string => (string) $path->filter('[data-cut-match-id]')->count(),
        ));
        self::assertSame(['Blader', 'QF', 'SF', 'F/3P', 'Finish'], $page->filter('[data-page-section="top-cut"] th')->each(
            static fn (Crawler $heading): string => trim($heading->text()),
        ));
        self::assertGreaterThan(0, $page->filter('[data-match-id] .font-black')->count(), 'The winner must be emphasised in each decided match.');
        self::assertCount(55, $page->filter('[data-swiss-round] [data-match-id] .line-through'));
        self::assertSame(['Rank', 'Blader', 'Match history', 'W-L-B'], array_slice($page->filter('[data-page-section="swiss-standings"] th')->each(
            static fn (Crawler $heading): string => trim($heading->text()),
        ), 0, 4));
        foreach ($page->filter('[data-swiss-round]') as $roundIndex => $roundNode) {
            $round = new Crawler($roundNode);
            self::assertCount(22, $round->filter('[data-match-history]'));
            self::assertCount(0, $round->filter('[data-match-history]')->reduce(
                static fn (Crawler $history): bool => $roundIndex + 1 !== $history->filter('[data-form-result]')->count(),
            ));
        }
        self::assertSelectorTextContains('th[title="Median-Buchholz"]', 'MB');
        self::assertCount(4, $page->filter('th.hidden.sm\\:table-cell'));
    }

    public function testAByeAppearsInTheRecordAndAsAStandaloneRoundEntry(): void
    {
        $page = $this->render($this->archivedByeEvent());
        $rounds = $page->filter('[data-swiss-round]');

        self::assertCount(2, $rounds);
        self::assertStringContainsString('1 matches · 1 bye', $rounds->eq(0)->text());
        self::assertStringContainsString('1 matches · 1 bye', $rounds->eq(1)->text());
        self::assertSame('Bye C B', preg_replace('/\s+/', ' ', trim($rounds->eq(0)->filter('[data-bye]')->text())));
        self::assertSame('Bye A W B', preg_replace('/\s+/', ' ', trim($rounds->eq(1)->filter('[data-bye]')->text())));

        $byeAStanding = $page->filter('[data-page-section="swiss-standings"] tbody tr')->reduce(
            static fn (Crawler $row): bool => str_contains($row->text(), 'Bye A'),
        );
        self::assertCount(1, $byeAStanding);
        self::assertStringContainsString('1-0-1', $byeAStanding->text());
        self::assertSame(['W', 'B'], $byeAStanding->filter('[data-form-result]')->each(
            static fn (Crawler $result): string => $result->text(),
        ));
    }

    private function teamEvent(): Tournament
    {
        return TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => '11 July Gamebreaker 2v2',
        ]);
    }

    private function archivedGamesplusEvent(): Tournament
    {
        $event = TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => 'Gamesplus 16-08',
            'challongeUrl' => 'https://challonge.com/nppk0890',
        ]);
        $swissPlayers = [];
        for ($number = 0; $number < 22; ++$number) {
            $name = sprintf('Swiss %02d', $number + 1);
            $swissPlayers[] = PlayerFactory::createOne(['name' => $name]);
        }
        $cutNames = ['Guzman93', 'Giglio', 'Sanya', 'Il-Karm', 'Sk3lli', 'Kemical', 'Obelix', 'Derius'];
        $cutPlayers = [];
        foreach ($cutNames as $name) {
            $cutPlayers[] = PlayerFactory::createOne(['name' => 'Cut '.$name]);
        }
        TournamentResultFactory::createOne([
            'tournament' => $event,
            'player' => $cutPlayers[0],
            'rank' => 1,
            'f1Points' => 25,
            'bonusPoints' => 10,
        ]);

        $swiss = new TournamentStage($event, 0, ChallongeStageKind::Group);
        $swiss->transcribe(ChallongeStageKind::Group, 'Group A', 'swiss', 5);
        $swissEntrants = [];
        for ($number = 0; $number < 22; ++$number) {
            $archivedName = sprintf('Swiss %02d', $number + 1).(0 === $number ? ' (invitation pending)' : '');
            $participant = new TournamentParticipant($swiss, $number + 1, $archivedName);
            $participant->transcribe(
                $participant->getName(),
                null,
                $number + 1,
                $number + 1,
                $number < 8,
                new ChallongeRecord(5 - ($number % 5), $number % 5, 0, 0, 5.0 - ($number % 5), 14.5, 3.25, 20 - $number),
            );
            $participant->isBlader($swissPlayers[$number]);
            $swissEntrants[] = $participant;
        }

        $matchId = 1;
        for ($round = 1; $round <= 5; ++$round) {
            for ($pair = 0; $pair < 11; ++$pair) {
                $player1 = $swissEntrants[$pair];
                $player2 = $swissEntrants[$pair + 11];
                $match = new TournamentMatch($swiss, $matchId++);
                $match->transcribe($round, null, 'complete', false, false);
                $match->between($player1, $player2);
                $match->scored(7, 4);
                $match->decided($player1, $player2);
            }
        }

        $cut = new TournamentStage($event, 1, ChallongeStageKind::Final);
        $cut->transcribe(ChallongeStageKind::Final, null, 'single elimination', 3);
        $cutEntrants = [];
        foreach ($cutNames as $number => $name) {
            $participant = new TournamentParticipant($cut, $number + 101, $name);
            $participant->transcribe($name, null, $number + 1, $number + 1, false, ChallongeRecord::nothing());
            $participant->isBlader($cutPlayers[$number]);
            $cutEntrants[] = $participant;
        }

        $this->cutMatch($cut, 101, 1, $cutEntrants[0], $cutEntrants[7], $cutEntrants[0]);
        $this->cutMatch($cut, 102, 1, $cutEntrants[1], $cutEntrants[6], $cutEntrants[1]);
        $this->cutMatch($cut, 103, 1, $cutEntrants[2], $cutEntrants[5], $cutEntrants[2]);
        $this->cutMatch($cut, 104, 1, $cutEntrants[3], $cutEntrants[4], $cutEntrants[3]);
        $this->cutMatch($cut, 105, 2, $cutEntrants[0], $cutEntrants[3], $cutEntrants[0]);
        $this->cutMatch($cut, 106, 2, $cutEntrants[1], $cutEntrants[2], $cutEntrants[1]);
        $this->cutMatch($cut, 107, 3, $cutEntrants[0], $cutEntrants[1], $cutEntrants[0]);
        $this->cutMatch($cut, 108, 3, $cutEntrants[3], $cutEntrants[2], $cutEntrants[2], consolation: true);

        $manager = self::getContainer()->get('doctrine')->getManager();
        $manager->persist($swiss);
        $manager->persist($cut);
        $manager->flush();

        return $event;
    }

    private function archivedByeEvent(): Tournament
    {
        $event = TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => 'Three blader Swiss',
        ]);
        $names = ['Bye A', 'Bye B', 'Bye C'];
        $players = array_map(
            static fn (string $name) => PlayerFactory::createOne(['name' => $name]),
            $names,
        );
        $stage = new TournamentStage($event, 0, ChallongeStageKind::Group);
        $stage->transcribe(ChallongeStageKind::Group, 'Group A', 'swiss', 2);

        $records = [
            new ChallongeRecord(1, 0, 0, 1, 2.0, 1.0, 0.0, 3),
            new ChallongeRecord(1, 1, 0, 0, 1.0, 1.0, 0.0, 0),
            new ChallongeRecord(0, 1, 0, 1, 1.0, 1.0, 0.0, -3),
        ];
        $participants = [];
        foreach ($names as $index => $name) {
            $participant = new TournamentParticipant($stage, $index + 1, $name);
            $participant->transcribe($name, null, $index + 1, $index + 1, false, $records[$index]);
            $participant->isBlader($players[$index]);
            $participants[] = $participant;
        }

        $this->cutMatch($stage, 201, 1, $participants[0], $participants[1], $participants[0]);
        $this->cutMatch($stage, 202, 2, $participants[1], $participants[2], $participants[1]);

        $manager = self::getContainer()->get('doctrine')->getManager();
        $manager->persist($stage);
        $manager->flush();

        return $event;
    }

    private function cutMatch(TournamentStage $stage, int $id, int $round, TournamentParticipant $player1, TournamentParticipant $player2, TournamentParticipant $winner, bool $consolation = false): void
    {
        $match = new TournamentMatch($stage, $id);
        $match->transcribe($round, null, 'complete', false, $consolation);
        $match->between($player1, $player2);
        $match->scored($winner === $player1 ? 7 : 4, $winner === $player2 ? 7 : 4);
        $match->decided($winner, $winner === $player1 ? $player2 : $player1);
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
