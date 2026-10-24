<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\ChallongeStageKind;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\PageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

#[WithStory(SeasonStory::class)]
final class TournamentSetScoresTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    #[DataProvider('formats')]
    public function testSetPointsAreAvailableInAClosedDisclosureInBothStages(string $format): void
    {
        $stage = $this->stage($format);
        $this->match($stage, 1, [[4, 2], [1, 4], [4, 2]]);
        $page = $this->render($stage);
        $detail = $page->filter('[data-match-id="1"] details[data-set-scores]');

        self::assertCount(1, $detail);
        self::assertNull($detail->attr('open'));
        self::assertStringContainsString('Set scores · 3 sets', $detail->filter('summary')->text());
        self::assertSame(['Claude', '4', '1', '4'], $this->setRow($detail, 0));
        self::assertSame(['Ziggy', '2', '4', '2'], $this->setRow($detail, 1));
        self::assertStringContainsString('2–1', $page->filter('[data-match-id="1"]')->text());
        if ('single elimination' === $format) {
            self::assertCount(1, $page->filter('[data-cut-round="Final"]'));
        }
    }

    public function testAStraightSetsLossPreservesPlayerOrderAndFivePointFinishes(): void
    {
        $stage = $this->stage();
        $this->match($stage, 1, [[2, 5], [0, 4]]);
        $detail = $this->render($stage)->filter('[data-set-scores]');

        self::assertSame(['Blader', 'Set 1', 'Set 2'], $detail->filter('thead th')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame(['Claude', '2', '0'], $this->setRow($detail, 0));
        self::assertSame(['Ziggy', '5', '4'], $this->setRow($detail, 1));
    }

    #[DataProvider('formats')]
    public function testSingleGamesAndAwardedMatchesNeverOfferSetScores(string $format): void
    {
        $stage = $this->stage($format);
        $this->match($stage, 1, [[7, 4]]);
        $this->match($stage, 2, [[4, 2], [4, 1]], forfeited: true);
        $this->match($stage, 3, [[4, 2], [4, 1]], bye: true);
        $page = $this->render($stage);

        self::assertCount(0, $page->filter('[data-set-scores]'));
        self::assertCount(0, $page->filter('[data-cut-rounds]'));
        self::assertStringNotContainsString('Set scores', $page->text());
    }

    public function testAThirdPlacePlayoffHasItsOwnDetailAfterTheFinal(): void
    {
        $stage = $this->stage('single elimination');
        $this->match($stage, 1, [[4, 2], [4, 1]]);
        $this->match($stage, 2, [[1, 4], [4, 0], [4, 2]], consolation: true);
        $page = $this->render($stage);

        self::assertSame(['Final', 'Third-place playoff'], $page->filter('[data-cut-round]')->each(static fn (Crawler $round): ?string => $round->attr('data-cut-round')));
        self::assertCount(1, $page->filter('[data-cut-round="Third-place playoff"] [data-match-id="2"] [data-set-scores]'));
        self::assertCount(1, $page->filter('[data-match-id="2"]'));
    }

    /** @return iterable<string, array{string}> */
    public static function formats(): iterable
    {
        yield 'Swiss' => ['swiss'];
        yield 'Knockout' => ['single elimination'];
    }

    /** @return list<string> */
    private function setRow(Crawler $detail, int $row): array
    {
        return $detail->filter('tbody tr')->eq($row)->filter('th, td')->each(static fn (Crawler $cell): string => $cell->text());
    }

    private function stage(string $format = 'swiss'): TournamentStage
    {
        $event = TournamentFactory::createOne(['season' => SeasonStory::paymentSeason()]);
        $kind = 'swiss' === $format ? ChallongeStageKind::Group : ChallongeStageKind::Final;
        $stage = new TournamentStage($event, 0, $kind);
        $stage->transcribe($kind, null, $format, 1);

        return $stage;
    }

    /** @param list<list<int>> $games */
    private function match(TournamentStage $stage, int $id, array $games, bool $forfeited = false, bool $bye = false, bool $consolation = false): void
    {
        $first = new TournamentParticipant($stage, $id * 2, 'Claude');
        $second = $bye ? null : new TournamentParticipant($stage, $id * 2 + 1, 'Ziggy');
        $match = new TournamentMatch($stage, $id);
        $match->transcribe(1, null, 'complete', $forfeited, $consolation);
        $match->between($first, $second);
        $match->transcribeGames($games);
        if (count($games) > 1) {
            $wins = count(array_filter($games, static fn (array $game): bool => $game[0] > $game[1]));
            $match->scored($wins, count($games) - $wins);
        } else {
            $match->scored($games[0][0], $games[0][1]);
        }
    }

    private function render(TournamentStage $stage): Crawler
    {
        $manager = self::getContainer()->get('doctrine')->getManager();
        $manager->persist($stage);
        $manager->flush();
        $page = $this->createBrowser()->request('GET', sprintf('/tournament/%d', $stage->getTournament()->getId()));
        self::assertResponseIsSuccessful();

        return $page;
    }
}
