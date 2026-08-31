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
use App\Tests\Story\SeasonStory;
use App\Tests\Support\PageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * `/tournaments`, the season shelf.
 *
 * The page exists so that an unranked event is reachable at all: it appears in
 * no season leaderboard, and every other route into a tournament used to go
 * through a season. So the assertions that matter are about **filing** — which
 * group each event lands in, and that the group headed "Unranked tournaments"
 * is a real group rather than a badge on a row.
 */
#[WithStory(SeasonStory::class)]
final class TournamentArchiveTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    public function testItRendersAgainstADatabaseWithNothingArchived(): void
    {
        $page = $this->createBrowser()->request('GET', '/tournaments');

        self::assertResponseIsSuccessful();

        // Both seasons still get a shelf, each saying it holds nothing.
        self::assertCount(2, $page->filter('[data-archive-shelf]'));
        self::assertStringContainsString('no events yet', $page->text());
        self::assertStringNotContainsString('Unranked tournaments', $page->text());
    }

    public function testItFilesEveryEventUnderTheSeasonItScoresIn(): void
    {
        $this->event('Gamesplus 16-08', '2026-08-16', SeasonStory::paymentSeason());
        $this->event('Gamesplus 23-08', '2026-08-23', SeasonStory::paymentSeason());
        $this->event('Preseason opener', '2026-06-20', SeasonStory::freeSeason());
        $this->event('Malta International Exhibition', '2026-08-30', null);

        $page = $this->createBrowser()->request('GET', '/tournaments');

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['paid-season', 'free-season', 'unranked'],
            $this->shelves($page),
        );

        self::assertSame(
            ['Gamesplus 16-08', 'Gamesplus 23-08'],
            $this->eventsOn($page, 'paid-season'),
        );
        self::assertSame(['Preseason opener'], $this->eventsOn($page, 'free-season'));
        self::assertSame(['Malta International Exhibition'], $this->eventsOn($page, 'unranked'));
    }

    /**
     * Decided on review: **not** "Outside the season", which reads as a place
     * events fell out of rather than a kind of event.
     */
    public function testTheUnrankedGroupIsHeadedUnrankedTournaments(): void
    {
        $this->event('Malta International Exhibition', '2026-08-30', null);

        $page = $this->createBrowser()->request('GET', '/tournaments');
        $shelf = $page->filter('[data-archive-shelf="unranked"]');

        self::assertStringContainsString('Unranked tournaments', $shelf->text());
        self::assertStringNotContainsString('Outside the season', $page->text());
    }

    public function testEveryRowReachesItsTournamentPage(): void
    {
        $ranked = $this->event('Gamesplus 16-08', '2026-08-16', SeasonStory::paymentSeason());
        $unranked = $this->event('Malta International Exhibition', '2026-08-30', null);

        $page = $this->createBrowser()->request('GET', '/tournaments');

        foreach ([$ranked, $unranked] as $event) {
            self::assertCount(
                1,
                $page->filter(sprintf('a[href="/tournament/%d"]', $event->getId())),
                sprintf('"%s" should reach its own page.', $event->getTitle()),
            );
        }
    }

    /**
     * A season scope holds only the tournaments assigned to that season — so
     * it can hold no unranked event, by definition.
     */
    public function testASeasonScopeHoldsOnlyItsOwnEvents(): void
    {
        $this->event('Gamesplus 16-08', '2026-08-16', SeasonStory::paymentSeason());
        $this->event('Preseason opener', '2026-06-20', SeasonStory::freeSeason());
        $this->event('Malta International Exhibition', '2026-08-30', null);

        $page = $this->createBrowser()->request('GET', '/tournaments?season=paid-season');

        self::assertResponseIsSuccessful();
        self::assertSame(['paid-season'], $this->shelves($page));
        self::assertSame(['Gamesplus 16-08'], $this->eventsOn($page, 'paid-season'));
        self::assertStringNotContainsString('Malta International Exhibition', $page->text());
    }

    public function testAnUnknownSeasonIsNotFound(): void
    {
        $this->createBrowser()->request('GET', '/tournaments?season=season-nine');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheSummaryCountsTheWholeArchiveIncludingUnrankedEvents(): void
    {
        $this->event('Gamesplus 16-08', '2026-08-16', SeasonStory::paymentSeason());
        $this->event('Malta International Exhibition', '2026-08-30', null);

        $page = $this->createBrowser()->request('GET', '/tournaments');
        $tiles = $page->filter('[data-page-section="archive-summary"] div')->each(
            static fn (Crawler $tile): string => trim((string) preg_replace('/\s+/', ' ', $tile->text())),
        );

        self::assertSame(
            ['2 events archived', '2 completed matches', '4 bladers', '2 seasons'],
            $tiles,
        );
    }

    /**
     * One archived event: two entrants, one completed match between them.
     */
    private function event(string $title, string $heldOn, ?object $season): Tournament
    {
        $event = TournamentFactory::createOne([
            'season' => $season,
            'title' => $title,
            'heldOn' => new \DateTimeImmutable($heldOn),
        ]);

        $bladers = [
            PlayerFactory::createOne(['name' => $title.' one']),
            PlayerFactory::createOne(['name' => $title.' two']),
        ];

        if (null !== $season) {
            TournamentResultFactory::createOne([
                'tournament' => $event,
                'player' => $bladers[0],
                'rank' => 1,
                'f1Points' => 25,
                'bonusPoints' => 10,
            ]);
        }

        $stage = new TournamentStage($event, 0, ChallongeStageKind::Group);
        $stage->transcribe(ChallongeStageKind::Group, 'Round robin', 'swiss', 1);

        $entrants = [];
        foreach ($bladers as $index => $blader) {
            $entrant = new TournamentParticipant($stage, $index + 1, $blader->getName());
            $entrant->transcribe($blader->getName(), null, $index + 1, $index + 1, false, new ChallongeRecord(1, 0, 0));
            $entrant->isBlader($blader);
            $entrants[] = $entrant;
        }

        $match = new TournamentMatch($stage, 1);
        $match->transcribe(1, null, 'complete', false, false);
        $match->between($entrants[0], $entrants[1]);
        $match->scored(7, 4);
        $match->decided($entrants[0], $entrants[1]);

        $manager = self::getContainer()->get('doctrine')->getManager();
        $manager->persist($stage);
        $manager->flush();

        return $event;
    }

    /**
     * @return list<string>
     */
    private function shelves(Crawler $page): array
    {
        return $page->filter('[data-archive-shelf]')->each(
            static fn (Crawler $shelf): string => (string) $shelf->attr('data-archive-shelf'),
        );
    }

    /**
     * @return list<string>
     */
    private function eventsOn(Crawler $page, string $shelf): array
    {
        return $page->filter(sprintf('[data-archive-shelf="%s"] [data-event-title]', $shelf))->each(
            static fn (Crawler $cell): string => trim($cell->text()),
        );
    }
}
