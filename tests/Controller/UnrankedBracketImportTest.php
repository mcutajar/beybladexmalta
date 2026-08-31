<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Dto\BracketImportData;
use App\Entity\Tournament;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\AdminPageTestCase;
use App\Tests\Support\FakeChallonge;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

/**
 * Importing a bracket as an event that belongs to no season.
 *
 * The same fixture bracket `BracketImportControllerTest` drives, taken down the
 * same path with one option changed — which is the point of 4A: no new control,
 * no new interaction, one more entry in a select that was already there.
 *
 * What must come out the other end is a complete transcription and **not one
 * scoring row**. Not zero-point ones: `TournamentResult` is the scoring record
 * and `getLeagueLeaderboard()` counts rows against each blader's best fourteen,
 * so a row paying nothing would displace one that pays.
 */
#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class UnrankedBracketImportTest extends AdminPageTestCase
{
    private const PAGE = '/admin/import';

    private const TITLE = 'Malta International Exhibition';

    private const DATE = '2026-08-16';

    private const UNKNOWN_KEY = 'guythebracketo';

    private const MISSPELLED_KEY = 'giglio15';

    public function testTheEntryFormOffersNoSeasonAboveTheSeasons(): void
    {
        $this->createBrowser()->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSeasonIsSelectable('bracket_import', BracketImportData::UNRANKED, 'No season');
        self::assertSeasonIsSelectable('bracket_import', 'paid-season');
    }

    /**
     * The preview is the guard, so it has to say all three things: that this
     * scores nothing, what it will archive, and the exact line it will append.
     */
    public function testThePreviewSaysWhatAnUnrankedConfirmWillAndWillNotWrite(): void
    {
        $this->league();

        $page = $this->fetchUnranked($this->createBrowser());

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-page-section="unranked-notice"]'));
        self::assertStringContainsString('imported as an unranked tournament', $page->text());
        self::assertStringContainsString('placings archived', $page->text());
        self::assertStringContainsString('app:archive-challonge', $page->text());

        self::assertNoEventWasImported();
    }

    /**
     * No F1, no bonus, no total, and no knockout bonus calculated at all.
     */
    public function testThePreviewShowsNoPointsColumnAnywhere(): void
    {
        $this->league();

        $page = $this->fetchUnranked($this->createBrowser());
        $headers = $page->filter('#placements thead th')->each(
            static fn (Crawler $cell): string => trim($cell->text()),
        );

        self::assertSame(['Rank', 'Blader', 'W-L-D', 'Form'], $headers);
        self::assertStringNotContainsString('Placements & points', $page->text());
        self::assertStringNotContainsString('points scored', $page->text());
    }

    /**
     * The trap #91 names. An unranked preview pays nothing, so every placement
     * fails `scores()` — and a refusal built on that list would reject every
     * unranked bracket as having no finishing order at all.
     */
    public function testAnAllZeroOrderIsNotMistakenForAnEmptyOne(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $page = $this->fetchUnranked($client);

        $this->confirm($client, $page, $this->everyNameAnswered());

        $event = self::findTournament(self::TITLE);

        self::assertFalse($event->isRanked());
        self::assertResponseRedirects(sprintf('/tournament/%d', $event->getId()));
    }

    public function testAnUnrankedImportArchivesEverythingAndScoresNothing(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $this->confirm($client, $this->fetchUnranked($client), $this->everyNameAnswered());

        $event = self::findTournament(self::TITLE);

        self::assertNull($event->getSeason());
        self::assertFalse($event->isRanked());
        self::assertCount(0, $event->getResults(), 'An unranked event writes no result row at all.');
        TournamentResultFactory::assert()->empty();

        // Archived in full, and the entrants still reach their bladers.
        self::assertGreaterThan(0, $event->getStages()->count());
        self::assertSame(
            ['Giglio', 'Guy "The {Bracket}" \\o/', 'Obelix', 'Sanya'],
            $this->bladersLinkedIn($event),
        );
    }

    /**
     * The ledger is written as usual and says the event was unranked, so a
     * replay into an empty schema reproduces it *and* its status.
     */
    public function testTheLedgerReplaysTheEventAsUnranked(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $this->confirm($client, $this->fetchUnranked($client), $this->everyNameAnswered());

        $ledger = (string) file_get_contents(self::ledgerPath());

        self::assertStringContainsString(sprintf(
            "php bin/console app:import-tournament '%s' '%s' '%s' --unranked --challonge=",
            self::TITLE,
            self::DATE,
            $this->importPath(),
        ), $ledger);
        self::assertStringNotContainsString('--season=', $ledger);
        self::assertStringNotContainsString('--knockout=', $ledger, 'An unranked event awards no knockout bonus.');
        self::assertStringContainsString('app:archive-challonge', $ledger);

        // The placements file is written exactly as a ranked import writes one.
        self::assertFileExists($this->importPath());
    }

    /**
     * A ranked import beside an unranked one is unaffected — the leaderboard
     * and every player total read the same rows they read before.
     */
    public function testAnUnrankedImportLeavesTheSeasonLeaderboardAlone(): void
    {
        $this->league();

        $ranked = TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => 'Gamesplus 09-08',
        ]);
        TournamentResultFactory::createOne([
            'tournament' => $ranked,
            'player' => PlayerFactory::find(['name' => 'Obelix']),
            'rank' => 1,
            'f1Points' => 25,
            'bonusPoints' => 10,
        ]);

        $client = $this->createBrowser();
        $this->confirm($client, $this->fetchUnranked($client), $this->everyNameAnswered());

        TournamentResultFactory::assert()->count(1);
        self::assertResultAtRank($ranked, 1, player: 'Obelix', f1Points: 25, bonusPoints: 10, totalPoints: 35);
    }

    /**
     * Ranked-only, and it says so rather than silently discarding ten
     * hand-typed names to deliver a message about the eleventh field.
     */
    public function testTheByHandFormRefusesUnrankedAndKeepsTheList(): void
    {
        $client = $this->createBrowser();

        $this->submitFormAt($client, self::PAGE, [
            'import_tournament[title]' => 'Pasted exhibition',
            'import_tournament[date]' => self::DATE,
            'import_tournament[season]' => BracketImportData::UNRANKED,
            'import_tournament[playerList]' => "Alpha\nBravo\nCharlie",
            'import_tournament[passphrase]' => self::ADMIN_PASSPHRASE,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('imported from its bracket, not from a pasted list', $client->getCrawler()->text());
        self::assertStringContainsString(
            "Alpha\nBravo\nCharlie",
            (string) $client->getCrawler()->filter('textarea[name="import_tournament[playerList]"]')->text(null, false),
        );

        self::assertNoEventWasImported();
        self::assertLedgerIsEmpty();
    }

    #[\Override]
    protected function artifactPaths(): array
    {
        return [...parent::artifactPaths(), $this->snapshotPath(), $this->importPath()];
    }

    /**
     * Every blader an archived entrant of this event points at, deduplicated
     * and sorted, which is the proof that an unranked import still resolves
     * names onto `Player` records.
     *
     * @return list<string>
     */
    private function bladersLinkedIn(Tournament $event): array
    {
        $names = [];

        foreach ($event->getStages() as $stage) {
            foreach ($stage->getParticipants() as $entrant) {
                $blader = $entrant->getPlayer();

                if (null !== $blader) {
                    $names[$blader->getName()] = true;
                }
            }
        }

        $names = array_keys($names);
        sort($names);

        return $names;
    }

    private function league(): void
    {
        PlayerFactory::createOne(['name' => 'Obelix']);
        PlayerFactory::createOne(['name' => 'Giglio']);

        PlayerAliasFactory::createOne([
            'player' => PlayerFactory::createOne(['name' => 'Sanya']),
            'alias' => 'Sanya0207',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function everyNameAnswered(): array
    {
        return [
            'decision['.self::UNKNOWN_KEY.']' => 'create',
            'decision['.self::MISSPELLED_KEY.']' => 'blader:'.PlayerFactory::find(['name' => 'Giglio'])->getId(),
        ];
    }

    private function fetchUnranked(KernelBrowser $client): Crawler
    {
        $this->submitFormAt($client, self::PAGE, [
            'bracket_import[challongeUrl]' => 'challonge.com/'.FakeChallonge::SLUG,
            'bracket_import[title]' => self::TITLE,
            'bracket_import[date]' => self::DATE,
            'bracket_import[season]' => BracketImportData::UNRANKED,
        ]);

        return $client->getCrawler();
    }

    /**
     * @param array<string, string> $answers
     */
    private function confirm(KernelBrowser $client, Crawler $page, array $answers): void
    {
        $form = $page->filter('form[name="bracket_confirm"]')->form();
        $form['bracket_confirm[passphrase]'] = self::ADMIN_PASSPHRASE;

        $client->submit($form, $answers);
    }

    private function snapshotPath(): string
    {
        return sprintf('%s/var/data/challonge/%s.json', self::projectDir(), FakeChallonge::SLUG);
    }

    private function importPath(): string
    {
        return sprintf(
            '%s/var/data/imports/%s-malta-international-exhibition.txt',
            self::projectDir(),
            self::DATE,
        );
    }
}
