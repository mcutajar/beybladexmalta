<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\PlayerFactory;
use App\Factory\TournamentFactory;
use App\Factory\TournamentResultFactory;
use App\Story\SeasonStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ImportTournamentControllerTest extends WebTestCase
{
    private const ADMIN_PASSPHRASE = 'test-passphrase';

    private const TITLE = 'Controller Test Cup';

    private const DATE = '2026-08-01';

    /**
     * Ten distinct bladers, best finish first.
     */
    private const PLACEMENTS = [
        'Giglio', 'Obelix', 'Lanzjan', 'Il-Karm', 'Evilbeys',
        'Derius', 'Rizzler', 'Steve', 'Southboy15', 'Tristan',
    ];

    private string $ledgerPath;

    private string $importPath;

    protected function setUp(): void
    {
        parent::setUp();

        $projectDir = dirname(__DIR__, 2);

        $this->ledgerPath = $projectDir.'/var/log/command_ledger.sh';

        $this->importPath = sprintf(
            '%s/var/data/imports/%s-controller-test-cup.txt',
            $projectDir,
            self::DATE,
        );

        $this->removeArtifacts();
    }

    protected function tearDown(): void
    {
        $this->removeArtifacts();

        parent::tearDown();
    }

    public function testPageDisplaysAllStorySeasons(): void
    {
        $client = $this->createBrowser();

        $client->request('GET', '/admin/import');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('admin_tournament_import');

        self::assertSelectorExists(
            'select[name="import_tournament[season]"] '
            .'option[value="paid-season"]'
        );

        self::assertSelectorExists(
            'select[name="import_tournament[season]"] '
            .'option[value="free-season"]'
        );
    }

    public function testIncorrectPassphraseImportsNothing(): void
    {
        $client = $this->createBrowser();

        $this->submitImport(
            client: $client,
            passphrase: 'wrong-passphrase',
        );

        self::assertResponseRedirects('/admin/import');

        TournamentFactory::assert()->empty();
        TournamentResultFactory::assert()->empty();
        PlayerFactory::assert()->empty();

        self::assertFileDoesNotExist($this->ledgerPath);
        self::assertFileDoesNotExist($this->importPath);

        $client->followRedirect();

        self::assertSelectorTextContains('body', 'Authentication failed.');
    }

    public function testSuccessfulImportAwardsF1PointsByFinishingRank(): void
    {
        $client = $this->createBrowser();

        $this->submitImport($client);

        self::assertResponseRedirects('/admin/import');

        TournamentFactory::assert()->count(1);
        TournamentResultFactory::assert()->count(10);
        PlayerFactory::assert()->count(10);

        $tournament = TournamentFactory::find([
            'title' => self::TITLE,
        ]);

        self::assertSame(
            SeasonStory::paymentSeason()->getId(),
            $tournament->getSeason()?->getId(),
        );

        self::assertSame(
            self::DATE,
            $tournament->getHeldOn()?->format('Y-m-d'),
        );

        $expectedF1Points = [25, 20, 15, 12, 10, 8, 6, 4, 2, 1];

        foreach (self::PLACEMENTS as $index => $playerName) {
            $rank = $index + 1;

            $result = TournamentResultFactory::find([
                'tournament' => $tournament,
                'rank' => $rank,
            ]);

            self::assertSame(
                $playerName,
                $result->getPlayer()?->getName(),
                sprintf('Rank %d should belong to %s.', $rank, $playerName),
            );

            self::assertSame(
                $expectedF1Points[$index],
                $result->getF1Points(),
                sprintf('Rank %d should score its F1 tier.', $rank),
            );
        }

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'Successfully imported "Controller Test Cup" with 10 player ranks.'
        );
    }

    public function testKnockoutWinnerReceivesTheBonus(): void
    {
        $client = $this->createBrowser();

        $this->submitImport(
            client: $client,
            knockoutWinner: '  eVILbeys  ',
        );

        self::assertResponseRedirects('/admin/import');

        $tournament = TournamentFactory::find([
            'title' => self::TITLE,
        ]);

        /*
         * Evilbeys finished fifth, so the knockout bonus stacks on top of
         * the ten F1 points awarded for that rank.
         */
        $winner = TournamentResultFactory::find([
            'tournament' => $tournament,
            'rank' => 5,
        ]);

        self::assertSame(10, $winner->getF1Points());
        self::assertSame(10, $winner->getBonusPoints());
        self::assertSame(20, $winner->getTotalPoints());

        $runnerUp = TournamentResultFactory::find([
            'tournament' => $tournament,
            'rank' => 1,
        ]);

        self::assertSame(0, $runnerUp->getBonusPoints());
    }

    public function testImportWritesAReplayableLedgerEntryAndSourceFile(): void
    {
        $client = $this->createBrowser();

        $this->submitImport(
            client: $client,
            challongeUrl: 'https://challonge.com/abcd1234',
            knockoutWinner: 'Evilbeys',
        );

        self::assertResponseRedirects('/admin/import');

        self::assertFileExists($this->importPath);

        self::assertSame(
            implode("\n", self::PLACEMENTS)."\n",
            file_get_contents($this->importPath),
        );

        self::assertFileExists($this->ledgerPath);

        $expectedCommand = sprintf(
            "php bin/console app:import-tournament %s %s %s --season=%s --challonge=%s --knockout=%s\n",
            escapeshellarg(self::TITLE),
            escapeshellarg(self::DATE),
            escapeshellarg($this->importPath),
            escapeshellarg('paid-season'),
            escapeshellarg('https://challonge.com/abcd1234'),
            escapeshellarg('Evilbeys'),
        );

        self::assertSame(
            $expectedCommand,
            file_get_contents($this->ledgerPath),
        );
    }

    public function testExistingPlayersAreReusedCaseInsensitively(): void
    {
        PlayerFactory::createOne([
            'name' => 'Giglio',
        ]);

        $client = $this->createBrowser();

        $this->submitImport(
            client: $client,
            placements: array_merge(
                ['  gIGLIO  '],
                array_slice(self::PLACEMENTS, 1),
            ),
        );

        self::assertResponseRedirects('/admin/import');

        PlayerFactory::assert()->count(10);

        PlayerFactory::assert()->exists([
            'name' => 'Giglio',
        ]);
    }

    public function testListWithoutExactlyTenPlacementsIsRejected(): void
    {
        $client = $this->createBrowser();

        $this->submitImport(
            client: $client,
            placements: array_slice(self::PLACEMENTS, 0, 9),
        );

        self::assertResponseRedirects('/admin/import');

        TournamentFactory::assert()->empty();
        self::assertFileDoesNotExist($this->ledgerPath);

        $client->followRedirect();

        self::assertSelectorTextContains('body', 'You provided 9.');
    }

    public function testLooselyFormattedDateIsRejected(): void
    {
        $client = $this->createBrowser();

        $this->submitImport(
            client: $client,
            date: '01/08/2026',
        );

        self::assertResponseRedirects('/admin/import');

        TournamentFactory::assert()->empty();
        self::assertFileDoesNotExist($this->ledgerPath);

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'Please use the strict format structure YYYY-MM-DD'
        );
    }

    public function testLedgerFailureCancelsTheImport(): void
    {
        /*
         * file_put_contents() cannot write to a directory as though it
         * were a regular file. This forces the ledger write to fail.
         */
        self::assertTrue(mkdir($this->ledgerPath));

        $client = $this->createBrowser();

        $this->submitImport($client);

        self::assertResponseRedirects('/admin/import');

        TournamentFactory::assert()->empty();
        TournamentResultFactory::assert()->empty();
        PlayerFactory::assert()->empty();

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'Critical failure: Failed to write the recovery artifacts, import cancelled.'
        );
    }

    public function testFailedFlushLeavesNoLedgerEntry(): void
    {
        /*
         * The same blader cannot place twice, so the unique constraint on
         * players.name rejects this list when the flush runs.
         */
        $placements = self::PLACEMENTS;
        $placements[1] = $placements[0];

        $client = $this->createBrowser();

        $this->submitImport(
            client: $client,
            placements: $placements,
        );

        self::assertResponseRedirects('/admin/import');

        $this->resetEntityManager();

        TournamentFactory::assert()->empty();
        TournamentResultFactory::assert()->empty();
        PlayerFactory::assert()->empty();

        /*
         * Replaying an orphan ledger line would recreate a tournament that
         * was never stored, so a failed import must not leave one behind.
         */
        self::assertFileDoesNotExist($this->ledgerPath);
        self::assertFileDoesNotExist($this->importPath);

        $client->followRedirect();

        self::assertSelectorTextContains('body', 'Import aborted:');
    }

    private function resetEntityManager(): void
    {
        /*
         * Doctrine closes the entity manager when a flush fails, so reset it
         * before asserting through the factories.
         */
        self::getContainer()->get('doctrine')->resetManager();
    }

    private function createBrowser(): KernelBrowser
    {
        /*
         * Foundry stories and factories may boot the kernel before this
         * point. WebTestCase needs to boot its browser kernel itself.
         */
        static::ensureKernelShutdown();

        return static::createClient();
    }

    /**
     * @param ?list<string> $placements
     */
    private function submitImport(
        KernelBrowser $client,
        ?string $seasonSlug = null,
        ?array $placements = null,
        string $date = self::DATE,
        string $challongeUrl = '',
        string $knockoutWinner = '',
        string $passphrase = self::ADMIN_PASSPHRASE,
    ): void {
        /*
         * Requesting the page first provides the real form and its CSRF
         * token.
         */
        $crawler = $client->request('GET', '/admin/import');

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter('form')
            ->first()
            ->form([
                'import_tournament[title]' => self::TITLE,
                'import_tournament[date]' => $date,
                'import_tournament[season]' => $seasonSlug
                    ?? SeasonStory::paymentSeason()->getSlug(),
                'import_tournament[challongeUrl]' => $challongeUrl,
                'import_tournament[knockoutWinner]' => $knockoutWinner,
                'import_tournament[playerList]' => implode(
                    "\n",
                    $placements ?? self::PLACEMENTS,
                ),
                'import_tournament[passphrase]' => $passphrase,
            ]);

        $client->submit($form);
    }

    private function removeArtifacts(): void
    {
        foreach ([$this->ledgerPath, $this->importPath] as $path) {
            if (is_file($path)) {
                unlink($path);

                continue;
            }

            if (is_dir($path)) {
                rmdir($path);
            }
        }
    }
}
