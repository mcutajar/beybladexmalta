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
 * `/tournament/{id}`: one page for both kinds of event.
 *
 * The ranked rendering is unchanged and is asserted here only to prove that —
 * `TournamentPageTest` and `ArchivedTournamentPageTest` say what it holds.
 * What is new is the unranked one: archive first, no League points card, and
 * no F1, bonus or total value anywhere on it.
 */
#[WithStory(SeasonStory::class)]
final class UnrankedTournamentPageTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    public function testTheSeasonScopedUrlsRedirectToTheCanonicalOne(): void
    {
        $event = TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => 'Gamesplus 16-08',
        ]);

        $canonical = sprintf('/tournament/%d', $event->getId());

        foreach ([
            sprintf('/season/paid-season/tournament/%d', $event->getId()),
            sprintf('/seasons/paid-season/tournament/%d', $event->getId()),
            sprintf('/preseason/tournament/%d', $event->getId()),
        ] as $old) {
            $this->createBrowser()->request('GET', $old);

            self::assertResponseRedirects($canonical, 301, sprintf('%s should redirect permanently.', $old));
        }
    }

    /**
     * The season in the old path never identified anything — the id did — so a
     * link naming a season the event was never in still reaches the event.
     */
    public function testAnOldUrlNamingTheWrongSeasonStillReachesTheEvent(): void
    {
        $event = TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => 'Gamesplus 23-08',
        ]);

        $this->createBrowser()->request('GET', sprintf('/season/free-season/tournament/%d', $event->getId()));

        self::assertResponseRedirects(sprintf('/tournament/%d', $event->getId()), 301);
    }

    public function testARankedPageStillLeadsWithItsLeaguePointsAndItsSeason(): void
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

        $page = $this->render($event);

        self::assertCount(1, $page->filter('[data-page-section="league-points"]'));
        self::assertCount(0, $page->filter('[data-page-section="unranked-notice"]'));
        self::assertStringContainsString('Paid Season', $page->text());
        self::assertStringContainsString('Official event record', $page->text());
        self::assertCount(1, $page->filter('a[href="/season/paid-season"]'));
    }

    public function testAnUnrankedPageNamesItselfAndCarriesNoLeaguePoints(): void
    {
        $event = $this->unrankedEvent();
        $page = $this->render($event);

        self::assertStringContainsString('Unranked tournament', $page->text());
        self::assertStringContainsString('Held on', $page->text());
        self::assertCount(1, $page->filter('a[href="https://challonge.com/exhibition"]'));

        self::assertCount(0, $page->filter('[data-page-section="league-points"]'), 'The League points card must be absent, not empty.');
        self::assertCount(1, $page->filter('[data-page-section="unranked-notice"]'));
        self::assertStringNotContainsString('Base F1', $page->text());
        self::assertStringNotContainsString('League points', $page->text());
    }

    /**
     * Option 3B. The archive leads — and on this page it is the whole of it.
     *
     * The top cut, the standings and the rounds are carried over from the
     * ranked page unchanged, and nothing scoring sits between them.
     */
    public function testAnUnrankedPageIsItsArchiveAndNothingElse(): void
    {
        $page = $this->render($this->unrankedEvent());

        $sections = $page->filter('[data-page-section]')->each(
            static fn (Crawler $node): string => (string) $node->attr('data-page-section'),
        );

        self::assertSame(
            ['unranked-notice', 'swiss-standings', 'swiss-rounds'],
            $sections,
        );
    }

    /**
     * The finishing order of an archived event *is* the ranking of its first
     * stage, which the Swiss standings block already renders — with the record,
     * the form and the tiebreak columns a separate block would drop. Repeating
     * it minus columns adds nothing, and the only thing it could have added is
     * the points, of which there are none.
     *
     * The block still exists and is still used, on the import preview, where
     * nothing is archived yet.
     */
    public function testItDoesNotRepeatTheStandingsAsASeparateFinishingOrder(): void
    {
        $page = $this->render($this->unrankedEvent());

        self::assertCount(0, $page->filter('[data-page-section="finishing-order"]'));

        // One table, holding the order once.
        self::assertSame(
            [['1', 'Exhibit A', 'W W', '2-0-0-0'], ['2', 'Exhibit B', 'L W', '1-1-0-0'], ['3', 'Exhibit C', 'L L', '0-2-0-0']],
            $this->standings($page),
        );
    }

    public function testAnUnrankedPageGoesBackToTheArchiveRatherThanASeason(): void
    {
        $page = $this->render($this->unrankedEvent());

        self::assertCount(1, $page->filter('a[href="/tournaments"]'));
        self::assertCount(0, $page->filter('a[href^="/season/"]'));
    }

    /**
     * The Swiss standings, cell by cell — the one place this page states the
     * order everybody finished in.
     *
     * The trailing columns are dropped on a phone (`hidden sm:table-cell`) and
     * the crawler reads the markup rather than the viewport, so the tiebreak
     * cells are sliced off here instead.
     *
     * @return list<list<string>>
     */
    private function standings(Crawler $page): array
    {
        return $page->filter('[data-page-section="swiss-standings"] tbody tr')->each(
            static fn (Crawler $row): array => array_slice($row->filter('td')->each(
                static fn (Crawler $cell): string => trim((string) preg_replace('/\s+/', ' ', $cell->text())),
            ), 0, 4),
        );
    }

    /**
     * A three-blader round robin belonging to no season: archived in full,
     * scoring nothing, and holding no `TournamentResult` at all.
     */
    private function unrankedEvent(): Tournament
    {
        $event = TournamentFactory::createOne([
            'season' => null,
            'title' => 'Malta International Exhibition',
            'challongeUrl' => 'https://challonge.com/exhibition',
        ]);

        $names = ['Exhibit A', 'Exhibit B', 'Exhibit C'];
        $records = [
            new ChallongeRecord(2, 0, 0, 0, 2.0, 1.0, 0.0, 14, 8),
            new ChallongeRecord(1, 1, 0, 0, 1.0, 1.0, 0.0, 11, 0),
            new ChallongeRecord(0, 2, 0, 0, 0.0, 1.0, 0.0, 8, -8),
        ];

        // Before the stage exists. Constructing one attaches it to the
        // tournament, and the next factory flush would then find a stage
        // nothing has persisted.
        $bladers = array_map(
            static fn (string $name) => PlayerFactory::createOne(['name' => $name]),
            $names,
        );

        $stage = new TournamentStage($event, 0, ChallongeStageKind::Group);
        $stage->transcribe(ChallongeStageKind::Group, 'Round robin', 'swiss', 2);

        $entrants = [];
        foreach ($names as $index => $name) {
            $entrant = new TournamentParticipant($stage, $index + 1, $name);
            $entrant->transcribe($name, null, $index + 1, $index + 1, false, $records[$index]);
            $entrant->isBlader($bladers[$index]);
            $entrants[] = $entrant;
        }

        $this->playedMatch($stage, 1, 1, $entrants[0], $entrants[1]);
        $this->playedMatch($stage, 2, 2, $entrants[1], $entrants[2]);
        $this->playedMatch($stage, 3, 2, $entrants[0], $entrants[2]);

        $manager = self::getContainer()->get('doctrine')->getManager();
        $manager->persist($stage);
        $manager->flush();

        return $event;
    }

    private function playedMatch(TournamentStage $stage, int $id, int $round, TournamentParticipant $winner, TournamentParticipant $loser): void
    {
        $match = new TournamentMatch($stage, $id);
        $match->transcribe($round, null, 'complete', false, false);
        $match->between($winner, $loser);
        $match->scored(7, 4);
        $match->decided($winner, $loser);
    }

    private function render(Tournament $event): Crawler
    {
        $page = $this->createBrowser()->request('GET', sprintf('/tournament/%d', $event->getId()));

        self::assertResponseIsSuccessful();

        return $page;
    }
}
