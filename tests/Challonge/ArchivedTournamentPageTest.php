<?php

declare(strict_types=1);

namespace App\Tests\Challonge;

use App\Entity\Tournament;
use App\Service\ChallongeArchiveService;
use App\Service\ChallongeSnapshotReader;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\PageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The tournament page, rendered from brackets the league actually played.
 *
 * `TournamentPageTest` builds an archive by hand, which is the right way to
 * pin a layout but makes every count true by construction — a page asserted
 * to reach sixty-three matches against a fixture built with sixty-three in it
 * is agreeing with itself. These read the tracked snapshots in
 * `var/data/challonge/`, archive them the way the backfill does, and put the
 * page to the shapes the corpus contains and a hand-built fixture does not:
 * a drawn match, three awarded ones, entrants who stopped turning up, and the
 * one cut that needed four rounds.
 */
#[WithStory(SeasonStory::class)]
final class ArchivedTournamentPageTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * The acceptance criterion on #57, against the bracket it names rather
     * than against a stand-in with the same dimensions.
     */
    public function testEveryMatchOfGamesplus1608IsReachableOnThePage(): void
    {
        $page = $this->archivedPage('nppk0890');

        self::assertCount(63, $this->matchIds($page));
    }

    /**
     * Bankai and Obelix drew on 12 July — the only drawn match in the corpus —
     * and Challonge scored them 1.5 for it. A record that read `1-3-0` beside
     * a score of 1.5 would be a row contradicting itself.
     */
    public function testADrawnMatchIsCountedInTheRecordAndShownInTheForm(): void
    {
        $page = $this->archivedPage('zx9el0js');
        $row = $this->standingsRowFor($page, 'Bankai');

        self::assertStringContainsString('1-3-1-0', $row->text());
        self::assertContains('T', $row->filter('[data-form-result]')->each(
            static fn (Crawler $result): string => $result->text(),
        ));
    }

    /**
     * Three matches on 9 August were awarded rather than played, and an
     * awarded match has an empty scoreline. `FF` is the bracket's own word for
     * it; an em dash would say the score was not captured.
     */
    public function testAnAwardedMatchSaysSoRatherThanShowingAScore(): void
    {
        $page = $this->archivedPage('6uwwkf2x');

        $scores = $page->filter('[data-swiss-round] [data-match-id]')->each(
            static fn (Crawler $match): string => $match->text(),
        );

        self::assertSame(3, array_sum(array_map(
            static fn (string $match): int => str_contains($match, 'FF') ? 1 : 0,
            $scores,
        )));
    }

    /**
     * Twelve bladers made the cut on 23 August, which is the only bracket that
     * needed four knockout rounds. The two opening ones have no name of their
     * own, so the scoreboard numbers them instead of inventing one.
     */
    public function testTheOneTwelveBladerCutNumbersItsOpeningRounds(): void
    {
        $page = $this->archivedPage('38ztp3w7');

        self::assertSame(
            ['Blader', 'R1', 'R2', 'SF', 'F/3P', 'Finish'],
            $page->filter('[data-page-section="top-cut"] th')->each(
                static fn (Crawler $heading): string => trim($heading->text()),
            ),
        );
        self::assertCount(12, $page->filter('[data-cut-path]'));
    }

    /**
     * Three bladers stopped turning up partway through 9 August without a bye
     * to explain it. A form strip is as long as the rounds somebody played, so
     * theirs are short — the alternative is inventing a result for a round
     * nobody has a record of.
     */
    public function testAnEntrantWhoStoppedPlayingHasAShortFormStrip(): void
    {
        $page = $this->archivedPage('6uwwkf2x');

        $short = array_filter(
            $page->filter('[data-page-section="swiss-standings"] tbody tr')->each(
                static fn (Crawler $row): int => $row->filter('[data-form-result]')->count(),
            ),
            static fn (int $length): bool => $length < 5,
        );
        sort($short);

        // Pegasus played three rounds, King and Lukas one each.
        self::assertSame([1, 1, 3], $short);
    }

    /**
     * Archives one captured bracket the way the backfill does, then renders
     * the event's public page.
     */
    private function archivedPage(string $slug): Crawler
    {
        $event = TournamentFactory::createOne([
            'title' => 'Event from '.$slug,
            'season' => SeasonStory::paymentSeason(),
            'challongeUrl' => 'https://challonge.com/'.$slug,
        ]);

        self::getContainer()->get(ChallongeArchiveService::class)->archive(
            $event,
            self::getContainer()->get(ChallongeSnapshotReader::class)->read($slug),
        );

        return $this->render($event);
    }

    /**
     * Every archived match the page puts somewhere, by Challonge id.
     *
     * The Swiss rounds and the cut scoreboard carry different attributes
     * because one is a list of matches and the other is a grid of them, so
     * "reachable" means named by either.
     *
     * @return list<string>
     */
    private function matchIds(Crawler $page): array
    {
        return array_values(array_unique($page->filter('[data-match-id], [data-cut-match-id]')->each(
            static fn (Crawler $match): string => (string) ($match->attr('data-match-id') ?? $match->attr('data-cut-match-id')),
        )));
    }

    private function standingsRowFor(Crawler $page, string $blader): Crawler
    {
        $row = $page->filter('[data-page-section="swiss-standings"] tbody tr')->reduce(
            static fn (Crawler $candidate): bool => str_contains($candidate->text(), $blader),
        );

        self::assertCount(1, $row, sprintf('Expected exactly one standings row naming "%s".', $blader));

        return $row;
    }

    private function render(Tournament $event): Crawler
    {
        $crawler = $this->createBrowser()->request(
            'GET',
            sprintf('/tournament/%d', $event->getId()),
        );

        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
