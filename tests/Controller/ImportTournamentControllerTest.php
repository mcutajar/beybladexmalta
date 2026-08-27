<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\AdminPageTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ImportTournamentControllerTest extends AdminPageTestCase
{
    private const PAGE = '/admin/import';

    private const TITLE = 'Controller Test Cup';

    private const DATE = '2026-08-01';

    private const SEASON = 'paid-season';

    /**
     * Ten distinct bladers, best finish first.
     */
    private const PLACEMENTS = [
        'Giglio', 'Obelix', 'Lanzjan', 'Il-Karm', 'Evilbeys',
        'Derius', 'Rizzler', 'Steve', 'Southboy15', 'Tristan',
    ];

    public function testPageDisplaysAllStorySeasons(): void
    {
        $client = $this->createBrowser();

        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertRouteSame('admin_tournament_import');

        self::assertSeasonIsSelectable('import_tournament', 'paid-season');
        self::assertSeasonIsSelectable('import_tournament', 'free-season');
    }

    public function testIncorrectPassphraseImportsNothing(): void
    {
        $client = $this->createBrowser();

        $this->submitImport($client, passphrase: 'wrong-passphrase');

        self::assertResponseRedirects(self::PAGE);
        self::assertNothingWasImported();
        self::assertLedgerIsEmpty();
        self::assertFileDoesNotExist($this->importPath());

        $this->assertFlashSays($client, 'Authentication failed.');
    }

    public function testSuccessfulImportAwardsF1PointsByFinishingRank(): void
    {
        $client = $this->createBrowser();

        $this->submitImport($client);

        self::assertResponseRedirects(self::PAGE);

        TournamentFactory::assert()->count(1);
        TournamentResultFactory::assert()->count(10);
        PlayerFactory::assert()->count(10);

        $tournament = self::findTournament(self::TITLE);

        self::assertSame(
            SeasonStory::paymentSeason()->getId(),
            $tournament->getSeason()->getId(),
        );

        self::assertSame(self::DATE, $tournament->getHeldOn()->format('Y-m-d'));
        self::assertPlacementsScoredInOrder($tournament, self::PLACEMENTS);

        $this->assertFlashSays(
            $client,
            'Successfully imported "Controller Test Cup" with 10 player ranks.',
        );
    }

    /**
     * The web form used to insist on exactly ten. The F1 matrix pays nothing
     * below tenth rather than refusing to be asked, and the league has held a
     * seven-entrant round robin, so a short list scores every place it has.
     */
    public function testAShortListScoresEveryPlaceItHas(): void
    {
        $client = $this->createBrowser();

        $shortlist = array_slice(self::PLACEMENTS, 0, 7);

        $this->submitImport($client, placements: $shortlist);

        self::assertResponseRedirects(self::PAGE);

        TournamentResultFactory::assert()->count(7);
        self::assertPlacementsScoredInOrder(self::findTournament(self::TITLE), $shortlist);

        $this->assertFlashSays(
            $client,
            'Successfully imported "Controller Test Cup" with 7 player ranks.',
        );
    }

    public function testKnockoutWinnerReceivesTheBonus(): void
    {
        $client = $this->createBrowser();

        $this->submitImport($client, knockoutWinner: '  eVILbeys  ');

        self::assertResponseRedirects(self::PAGE);

        $tournament = self::findTournament(self::TITLE);

        /*
         * Evilbeys finished fifth, so the knockout bonus stacks on top of the
         * ten F1 points awarded for that rank.
         */
        self::assertResultAtRank(
            $tournament,
            rank: 5,
            f1Points: 10,
            bonusPoints: 10,
            totalPoints: 20,
        );

        self::assertResultAtRank($tournament, rank: 1, bonusPoints: 0);
    }

    public function testImportWritesAReplayableLedgerEntryAndSourceFile(): void
    {
        $client = $this->createBrowser();

        $this->submitImport(
            $client,
            challongeUrl: 'https://challonge.com/abcd1234',
            knockoutWinner: 'Evilbeys',
        );

        self::assertResponseRedirects(self::PAGE);

        self::assertFileExists($this->importPath());

        self::assertSame(
            implode("\n", self::PLACEMENTS)."\n",
            file_get_contents($this->importPath()),
        );

        self::assertLedgerRecordsImport(
            title: self::TITLE,
            heldOn: self::DATE,
            sourcePath: $this->importPath(),
            seasonSlug: self::SEASON,
            challongeUrl: 'https://challonge.com/abcd1234',
            knockoutWinner: 'Evilbeys',
        );
    }

    public function testExistingPlayersAreReusedCaseInsensitively(): void
    {
        PlayerFactory::createOne(['name' => 'Giglio']);

        $client = $this->createBrowser();

        $this->submitImport($client, placements: [
            '  gIGLIO  ',
            ...array_slice(self::PLACEMENTS, 1),
        ]);

        self::assertResponseRedirects(self::PAGE);

        PlayerFactory::assert()->count(10);
        PlayerFactory::assert()->exists(['name' => 'Giglio']);
    }

    /**
     * @param array<string, mixed> $overrides fields to replace on the form
     */
    #[DataProvider('rejectedSubmissions')]
    public function testItRejectsAnUnusableSubmission(
        array $overrides,
        string $expectedMessage,
    ): void {
        $client = $this->createBrowser();

        $this->submitImport($client, ...$overrides);

        self::assertResponseRedirects(self::PAGE);
        self::assertNothingWasImported();
        self::assertLedgerIsEmpty();

        $this->assertFlashSays($client, $expectedMessage);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function rejectedSubmissions(): iterable
    {
        yield 'a list naming nobody' => [
            ['placements' => []],
            'The player list must name at least one blader',
        ];

        yield 'loosely formatted date' => [
            ['date' => '01/08/2026'],
            'Please use the strict format structure YYYY-MM-DD',
        ];
    }

    public function testLedgerFailureCancelsTheImport(): void
    {
        self::blockLedgerWrites();

        $client = $this->createBrowser();

        $this->submitImport($client);

        self::assertResponseRedirects(self::PAGE);
        self::assertNothingWasImported();

        $this->assertFlashSays(
            $client,
            'Critical failure: Failed to write the recovery artifacts, import cancelled.',
        );
    }

    public function testFailedFlushLeavesNoLedgerEntry(): void
    {
        /*
         * The same blader cannot place twice, so the unique constraint on
         * players.name rejects this list when the flush runs.
         */
        $duplicated = self::PLACEMENTS;
        $duplicated[1] = $duplicated[0];

        $client = $this->createBrowser();

        $this->submitImport($client, placements: $duplicated);

        self::assertResponseRedirects(self::PAGE);

        $this->resetEntityManager();

        self::assertNothingWasImported();

        /*
         * Replaying an orphan ledger line would recreate a tournament that was
         * never stored, so a failed import must not leave one behind.
         */
        self::assertLedgerIsEmpty();
        self::assertFileDoesNotExist($this->importPath());

        $this->assertFlashSays($client, 'Import aborted:');
    }

    #[\Override]
    protected function artifactPaths(): array
    {
        return [...parent::artifactPaths(), $this->importPath()];
    }

    /**
     * The import service names the source file after the date and title.
     */
    private function importPath(): string
    {
        return sprintf(
            '%s/var/data/imports/%s-controller-test-cup.txt',
            self::projectDir(),
            self::DATE,
        );
    }

    /**
     * @param list<string> $placements best finish first
     */
    private function submitImport(
        KernelBrowser $client,
        array $placements = self::PLACEMENTS,
        string $date = self::DATE,
        string $seasonSlug = self::SEASON,
        string $challongeUrl = '',
        string $knockoutWinner = '',
        string $passphrase = self::ADMIN_PASSPHRASE,
    ): void {
        $this->submitFormAt($client, self::PAGE, [
            'import_tournament[title]' => self::TITLE,
            'import_tournament[date]' => $date,
            'import_tournament[season]' => $seasonSlug,
            'import_tournament[challongeUrl]' => $challongeUrl,
            'import_tournament[knockoutWinner]' => $knockoutWinner,
            'import_tournament[playerList]' => implode("\n", $placements),
            'import_tournament[passphrase]' => $passphrase,
        ]);
    }
}
