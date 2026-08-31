<?php

declare(strict_types=1);

namespace App\Tests\Challonge;

use App\Entity\Player;
use App\Service\ChallongeArchiveService;
use App\Service\ChallongeSnapshotReader;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\PageTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The player profile, built from brackets the league actually played.
 *
 * The same discipline as `ArchivedTournamentPageTest`, for the same reason: a
 * career asserted against a fixture built by hand agrees with itself, and every
 * counting rule #58 settled is about a shape only the corpus contains — the one
 * drawn match, the four awarded ones, and the blader who is two entrant rows in
 * a single evening.
 */
#[WithStory(SeasonStory::class)]
final class ArchivedPlayerProfileTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * Markinu played five Swiss rounds and one quarter-final on 16 August, and
     * the two halves of that evening are two `TournamentParticipant` rows in
     * disjoint Challonge id spaces. A career joined through either row alone
     * would be missing the other half of it.
     */
    public function testEveryMatchOfAnEventIsOnTheProfileAcrossBothStages(): void
    {
        $page = $this->profileFor('Markinu', 'nppk0890');

        self::assertCount(6, $page->filter('[data-career-match]'));
        self::assertSame(
            ['Swiss', 'Top cut'],
            $page->filter('[data-career-stage]')->each(
                static fn (Crawler $stage): string => (string) $stage->attr('data-career-stage'),
            ),
        );
    }

    /**
     * Bankai and Obelix drew on 12 July — the only drawn match in the corpus,
     * and Challonge scored them 1.5 each for it. A record that folded it into
     * the losses would be the bug #57 shipped in its first draft, told again.
     */
    public function testTheOneDrawnMatchIsAThirdOutcomeRatherThanALoss(): void
    {
        $page = $this->profileFor('Bankai', 'zx9el0js', ['Obelix']);

        self::assertStringContainsString('5 matches · 1–3–1', $page->filter('[data-career-summary]')->text());
        self::assertStringContainsString('0–0–1', $page->filter('[data-rival="Obelix"]')->text());
    }

    /**
     * A blader level with somebody is neither ahead of them nor chasing them,
     * and on this bracket the draw is the only thing that puts anyone there.
     */
    public function testALevelRecordIsGroupedAsEven(): void
    {
        $page = $this->profileFor('Obelix', 'zx9el0js', ['Bankai']);

        self::assertCount(1, $page->filter('[data-page-section="rivals-even"] [data-rival="Bankai"]'));
        self::assertCount(0, $page->filter('[data-page-section="rivals-behind"] [data-rival="Bankai"]'));
    }

    /**
     * Privv was awarded away on 12 July: a real loss, and no scoreline at all.
     * It counts in the record and contributes nothing to the points, which is
     * the rule that stops four matches quietly dragging every rate down.
     */
    public function testAnAwardedMatchCountsInTheRecordButNotInThePoints(): void
    {
        $page = $this->profileFor('Privv', 'zx9el0js');

        $forfeits = array_filter(
            $page->filter('[data-career-match]')->each(
                static fn (Crawler $match): string => preg_replace('/\s+/', ' ', $match->text()) ?? '',
            ),
            static fn (string $match): bool => str_contains($match, 'FF'),
        );

        self::assertCount(1, $forfeits);
        self::assertStringContainsString('L FF', (string) reset($forfeits));

        // Four matches played, and the points are the sum of the three scored.
        self::assertStringContainsString('4 matches · 0–4', $page->filter('[data-career-summary]')->text());
        self::assertSame('11', $this->kpi($page, 'points scored'));
    }

    /**
     * A blader with nothing archived is not a broken page. The two 2v2
     * evenings archive no matches at all, so this is a state the site really
     * reaches rather than a defensive branch.
     */
    public function testABladerWithNoArchivedMatchesGetsAnEmptyState(): void
    {
        $page = $this->render(PlayerFactory::createOne(['name' => 'Tristan']));

        self::assertCount(1, $page->filter('[data-page-section="career-empty"]'));
        self::assertCount(0, $page->filter('[data-career-match]'));

        /*
         * Nothing above the points table feeds scoring, and nothing below it
         * is affected by there being no archive. Since #95 the points table is
         * one block per season rather than one table with an empty row, so a
         * blader who has scored nowhere gets the empty state instead — there
         * is no season to head a block with.
         */
        self::assertCount(0, $page->filter('[data-page-section="league-points"]'));
        self::assertCount(1, $page->filter('[data-page-section="league-points-empty"]'));
    }

    /**
     * The unfinished bracket assigns Irmied u Gebel and Mafia to an open
     * match, but records no result. Merely knowing both entrants must not turn
     * that future match into the drawn match `outcomeFor()` uses as its
     * fallback when a completed match has neither a winner nor a loser.
     */
    public function testAnOpenMatchIsNotCountedAsAPlayedDraw(): void
    {
        $page = $this->profileFor('irmied u gebel', 'uhxii7az', ['mafia']);

        self::assertCount(5, $page->filter('[data-career-match]'));
        self::assertStringContainsString('5 matches · 5–0', $page->filter('[data-career-summary]')->text());
    }

    /**
     * Archives one captured bracket the way the backfill does, having first
     * created the bladers it should resolve to.
     *
     * Only the ones a test names: an entrant nobody has told us about stays
     * attached to nobody, which is what a bracket archived outside the import
     * screen looks like and what the opponent column has to survive.
     *
     * @param list<string> $others other entrants to create bladers for
     */
    private function profileFor(string $blader, string $slug, array $others = []): Crawler
    {
        $player = PlayerFactory::createOne(['name' => $blader]);
        foreach ($others as $name) {
            PlayerFactory::createOne(['name' => $name]);
        }

        $event = TournamentFactory::createOne([
            'title' => 'Event from '.$slug,
            'season' => SeasonStory::paymentSeason(),
            'challongeUrl' => 'https://challonge.com/'.$slug,
        ]);

        self::getContainer()->get(ChallongeArchiveService::class)->archive(
            $event,
            self::getContainer()->get(ChallongeSnapshotReader::class)->read($slug),
        );

        return $this->render($player);
    }

    private function kpi(Crawler $page, string $label): string
    {
        $tile = $page->filter('dl > div')->reduce(
            static fn (Crawler $candidate): bool => str_contains(strtolower($candidate->text()), $label),
        );

        self::assertCount(1, $tile, sprintf('Expected exactly one KPI tile labelled "%s".', $label));

        return trim($tile->filter('dd')->text());
    }

    private function render(Player $player): Crawler
    {
        $crawler = $this->createBrowser()->request(
            'GET',
            sprintf('/player/%s', $player->getSlug()),
        );

        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
