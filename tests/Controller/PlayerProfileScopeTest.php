<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\ChallongeRecord;
use App\Dto\ChallongeStageKind;
use App\Entity\Player;
use App\Entity\Season;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Support\PageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The canonical player URL and what a scope does to the page.
 *
 * The rule under test is narrow and unforgiving: **points are grouped by
 * season and never totalled across them.** Best 14 is a season's cap — a
 * fourteen-result cap applied to a whole career would mean nothing, and
 * summing across seasons is what the scope contract forbids. So Overall shows
 * one block per season with its own subtotal and no grand total anywhere.
 *
 * The worked example is the epic's: a blader with 150 points over Season 1 and
 * 60 over the preseason shows both, separately, and 210 nowhere.
 */
final class PlayerProfileScopeTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    public function testTheCanonicalUrlIsTheSlugAndIsSeasonIndependent(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Markinu']);

        $page = $this->createBrowser()->request('GET', '/player/markinu');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Markinu', $page->text());
        self::assertSame('markinu', $blader->getSlug());
    }

    /**
     * The slug is persisted, so correcting a display name does not move a URL
     * somebody has already shared.
     */
    public function testCorrectingADisplayNameDoesNotMoveTheUrl(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Markinu']);

        $blader->setName('MarKinu');
        self::getContainer()->get('doctrine')->getManager()->flush();

        $this->createBrowser()->request('GET', '/player/markinu');

        self::assertResponseIsSuccessful();
    }

    public function testEverySeasonScopedPlayerUrlRedirectsAndKeepsItsSeason(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Markinu']);
        SeasonFactory::createOne(['slug' => 'preseason-1', 'name' => 'Preseason 1']);
        $id = $blader->getId();

        foreach ([
            sprintf('/season/preseason-1/player/%d', $id) => '/player/markinu?season=preseason-1',
            sprintf('/seasons/preseason-1/player/%d', $id) => '/player/markinu?season=preseason-1',
            sprintf('/preseason/player/%d', $id) => '/player/markinu?season=preseason-1',
        ] as $old => $canonical) {
            $this->createBrowser()->request('GET', $old);

            self::assertResponseRedirects($canonical, 301, sprintf('%s should redirect permanently.', $old));
        }
    }

    public function testOverallShowsOneBlockPerSeasonAndNoTotalAcrossThem(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Markinu']);
        $preseason = SeasonFactory::createOne(['slug' => 'preseason-1', 'name' => 'Preseason 1', 'requiresPayment' => false]);
        $season = SeasonFactory::createOne(['slug' => '1', 'name' => 'Season 1', 'requiresPayment' => false]);

        $this->scored($blader, $preseason, 'Gamebreaker 20-06', '2026-06-20', 60);
        $this->scored($blader, $season, 'Gamesplus 04-07', '2026-07-04', 90);
        $this->scored($blader, $season, 'Gamesplus 23-08', '2026-08-23', 60);

        $page = $this->createBrowser()->request('GET', '/player/markinu');

        self::assertResponseIsSuccessful();
        self::assertSame(['1', 'preseason-1'], $this->pointsBlocks($page));
        self::assertSame(['150 pts', '60 pts'], $this->subtotals($page));

        // The one number the contract forbids.
        self::assertStringNotContainsString('210', $page->text());
    }

    public function testASeasonScopeShowsThatSeasonsBlockAlone(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Markinu']);
        $preseason = SeasonFactory::createOne(['slug' => 'preseason-1', 'name' => 'Preseason 1', 'requiresPayment' => false]);
        $season = SeasonFactory::createOne(['slug' => '1', 'name' => 'Season 1', 'requiresPayment' => false]);

        $this->scored($blader, $preseason, 'Gamebreaker 20-06', '2026-06-20', 60);
        $this->scored($blader, $season, 'Gamesplus 04-07', '2026-07-04', 90);

        $page = $this->createBrowser()->request('GET', '/player/markinu?season=1');

        self::assertResponseIsSuccessful();
        self::assertSame(['1'], $this->pointsBlocks($page));
        self::assertSame(['90 pts'], $this->subtotals($page));
        self::assertStringNotContainsString('Gamebreaker 20-06', $page->text());
    }

    public function testAnUnknownSeasonIsNotFound(): void
    {
        PlayerFactory::createOne(['name' => 'Markinu']);

        $this->createBrowser()->request('GET', '/player/markinu?season=season-nine');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * The whole reason the profile had to change: an unranked event has
     * matches and no points, so it belongs in one of these two places and
     * emphatically not in the other.
     */
    public function testAnUnrankedEventIsInTheTimelineAndNeverInThePointsCard(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Markinu']);
        $season = SeasonFactory::createOne(['slug' => '1', 'name' => 'Season 1', 'requiresPayment' => false]);

        $this->scored($blader, $season, 'Gamesplus 04-07', '2026-07-04', 25);
        $this->archivedEvent($blader, null, 'Malta International Exhibition', '2026-08-30');

        $page = $this->createBrowser()->request('GET', '/player/markinu');

        self::assertResponseIsSuccessful();

        $timeline = $page->filter('[data-page-section="career-timeline"]')->text();
        self::assertStringContainsString('Malta International Exhibition', $timeline);
        self::assertStringContainsString('Unranked', $timeline);

        foreach ($page->filter('[data-page-section="league-points"]') as $block) {
            self::assertStringNotContainsString(
                'Malta International Exhibition',
                (new Crawler($block))->text(),
                'An unranked event has no result row, so it can never appear in the points card.',
            );
        }
    }

    /**
     * Career figures are match-derived, so they answer at either scope — and
     * Overall counts an unranked event's matches like any other.
     */
    public function testCareerFiguresIncludeUnrankedEventsAndNarrowWithTheScope(): void
    {
        $blader = PlayerFactory::createOne(['name' => 'Markinu']);
        $season = SeasonFactory::createOne(['slug' => '1', 'name' => 'Season 1', 'requiresPayment' => false]);

        $this->archivedEvent($blader, $season, 'Gamesplus 04-07', '2026-07-04');
        $this->archivedEvent($blader, null, 'Malta International Exhibition', '2026-08-30');

        $overall = $this->createBrowser()->request('GET', '/player/markinu');

        self::assertSame(
            ['Malta International Exhibition', 'Gamesplus 04-07'],
            $this->timeline($overall),
        );

        $scoped = $this->createBrowser()->request('GET', '/player/markinu?season=1');

        self::assertSame(['Gamesplus 04-07'], $this->timeline($scoped));
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

    /**
     * One archived event with a single completed match, so it lands in the
     * timeline whether or not it scores.
     */
    private function archivedEvent(Player $blader, ?Season $season, string $title, string $heldOn): Tournament
    {
        $opponent = PlayerFactory::createOne(['name' => $title.' opponent']);

        $event = TournamentFactory::createOne([
            'season' => $season,
            'title' => $title,
            'heldOn' => new \DateTimeImmutable($heldOn),
        ]);

        $stage = new TournamentStage($event, 0, ChallongeStageKind::Group);
        $stage->transcribe(ChallongeStageKind::Group, 'Round robin', 'swiss', 1);

        $entrants = [];
        foreach ([$blader, $opponent] as $index => $person) {
            $entrant = new TournamentParticipant($stage, $index + 1, $person->getName());
            $entrant->transcribe($person->getName(), null, $index + 1, $index + 1, false, new ChallongeRecord(1, 0, 0));
            $entrant->isBlader($person);
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
    private function timeline(Crawler $page): array
    {
        return $page->filter('[data-career-event]')->each(
            static fn (Crawler $event): string => (string) $event->attr('data-career-event'),
        );
    }

    /**
     * @return list<string>
     */
    private function pointsBlocks(Crawler $page): array
    {
        return $page->filter('[data-points-season]')->each(
            static fn (Crawler $block): string => (string) $block->attr('data-points-season'),
        );
    }

    /**
     * @return list<string>
     */
    private function subtotals(Crawler $page): array
    {
        return $page->filter('[data-season-total]')->each(
            static fn (Crawler $total): string => trim((string) preg_replace('/\s+/', ' ', $total->text())),
        );
    }
}
