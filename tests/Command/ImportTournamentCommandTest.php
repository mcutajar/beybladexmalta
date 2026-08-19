<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Factory\PlayerFactory;
use App\Factory\SeasonFactory;
use App\Factory\TournamentFactory;
use App\Factory\TournamentResultFactory;
use App\Story\SeasonStory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ImportTournamentCommandTest extends KernelTestCase
{
    use Factories;

    private const TITLE = 'Command Test Cup';

    private const DATE = '2026-08-02';

    private string $ledgerPath;

    private string $placementFilePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerPath = dirname(__DIR__, 2)
            .'/var/log/command_ledger.sh';

        $this->placementFilePath = sprintf(
            '%s/placements-%s.txt',
            sys_get_temp_dir(),
            bin2hex(random_bytes(6)),
        );

        $this->removeArtifacts();
    }

    protected function tearDown(): void
    {
        $this->removeArtifacts();

        parent::tearDown();
    }

    public function testItImportsAPlacementFile(): void
    {
        $this->writePlacementFile([
            'Giglio', 'Obelix', 'Lanzjan', 'Il-Karm', 'Evilbeys',
            'Derius', 'Rizzler', 'Steve', 'Southboy15', 'Tristan',
        ]);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        self::assertStringContainsString(
            'Successfully imported "Command Test Cup" into Paid Season.'
            .' Logged 10 player placements.',
            $this->displayOf($tester),
        );

        TournamentFactory::assert()->count(1);
        TournamentResultFactory::assert()->count(10);
        PlayerFactory::assert()->count(10);

        $tournament = TournamentFactory::find([
            'title' => self::TITLE,
        ]);

        self::assertSame(
            self::DATE,
            $tournament->getHeldOn()?->format('Y-m-d'),
        );

        $winner = TournamentResultFactory::find([
            'tournament' => $tournament,
            'rank' => 1,
        ]);

        self::assertSame('Giglio', $winner->getPlayer()?->getName());
        self::assertSame(25, $winner->getF1Points());
        self::assertSame(0, $winner->getBonusPoints());

        $lastPlace = TournamentResultFactory::find([
            'tournament' => $tournament,
            'rank' => 10,
        ]);

        self::assertSame(1, $lastPlace->getF1Points());
    }

    public function testItAppliesManualAndKnockoutBonuses(): void
    {
        $this->writePlacementFile([
            'Giglio',
            'Obelix, 5',
            'Lanzjan',
        ]);

        $tester = $this->execute([
            '--knockout' => 'obelix',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $second = TournamentResultFactory::find([
            'tournament' => TournamentFactory::find(['title' => self::TITLE]),
            'rank' => 2,
        ]);

        /*
         * Five bonus points from the file, ten more for taking the
         * knockout bracket, on top of the second place F1 tier.
         */
        self::assertSame(20, $second->getF1Points());
        self::assertSame(15, $second->getBonusPoints());
        self::assertSame(35, $second->getTotalPoints());
    }

    public function testItReusesAnExistingPlayerCaseInsensitively(): void
    {
        PlayerFactory::createOne([
            'name' => 'Giglio',
        ]);

        $this->writePlacementFile([
            '  gIGLIO  ',
            'Obelix',
        ]);

        $tester = $this->execute();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        PlayerFactory::assert()->count(2);

        PlayerFactory::assert()->exists([
            'name' => 'Giglio',
        ]);
    }

    public function testItLogsAReplayableLedgerEntry(): void
    {
        $this->writePlacementFile([
            'Giglio',
            'Obelix',
        ]);

        $tester = $this->execute([
            '--challonge' => 'https://challonge.com/abcd1234',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        $expectedCommand = sprintf(
            "php bin/console app:import-tournament %s %s %s --season=%s --challonge=%s\n",
            escapeshellarg(self::TITLE),
            escapeshellarg(self::DATE),
            escapeshellarg($this->placementFilePath),
            escapeshellarg('paid-season'),
            escapeshellarg('https://challonge.com/abcd1234'),
        );

        self::assertFileExists($this->ledgerPath);

        self::assertSame(
            $expectedCommand,
            file_get_contents($this->ledgerPath),
        );
    }

    public function testItRejectsAnUnreadableFile(): void
    {
        $tester = $this->execute();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        self::assertStringContainsString(
            sprintf(
                'File path "%s" is unreadable or does not exist.',
                $this->placementFilePath,
            ),
            $this->displayOf($tester),
        );

        TournamentFactory::assert()->empty();
    }

    public function testItRejectsALooselyFormattedDate(): void
    {
        $this->writePlacementFile([
            'Giglio',
        ]);

        $tester = $this->execute([
            'date' => '02/08/2026',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        self::assertStringContainsString(
            'Invalid date format provided. Please use YYYY-MM-DD.',
            $this->displayOf($tester),
        );

        TournamentFactory::assert()->empty();
        self::assertFileDoesNotExist($this->ledgerPath);
    }

    public function testItCreatesAMissingSeasonOnConfirmation(): void
    {
        $this->writePlacementFile([
            'Giglio',
        ]);

        $tester = $this->execute([
            '--season' => 'brand-new-season',
        ], ['yes']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());

        SeasonFactory::assert()->exists([
            'slug' => 'brand-new-season',
            'name' => 'Brand New Season',
        ]);

        TournamentFactory::assert()->count(1);
    }

    public function testItAbortsWhenSeasonCreationIsDeclined(): void
    {
        $this->writePlacementFile([
            'Giglio',
        ]);

        $tester = $this->execute([
            '--season' => 'brand-new-season',
        ], ['no']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());

        SeasonFactory::assert()->notExists([
            'slug' => 'brand-new-season',
        ]);

        TournamentFactory::assert()->empty();
        self::assertFileDoesNotExist($this->ledgerPath);
    }

    /**
     * SymfonyStyle hard-wraps its blocks, so collapse the whitespace before
     * matching against a message.
     */
    private function displayOf(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }

    /**
     * @param array<string, string> $overrides
     * @param list<string>          $inputs
     */
    private function execute(
        array $overrides = [],
        array $inputs = [],
    ): CommandTester {
        $tester = $this->createCommandTester();

        if ([] !== $inputs) {
            $tester->setInputs($inputs);
        }

        $tester->execute(array_merge([
            'title' => self::TITLE,
            'date' => self::DATE,
            'file' => $this->placementFilePath,
            '--season' => SeasonStory::paymentSeason()->getSlug(),
        ], $overrides));

        return $tester;
    }

    private function createCommandTester(): CommandTester
    {
        $application = new Application(self::bootKernel());

        return new CommandTester(
            $application->find('app:import-tournament'),
        );
    }

    /**
     * @param list<string> $lines
     */
    private function writePlacementFile(array $lines): void
    {
        file_put_contents(
            $this->placementFilePath,
            implode("\n", $lines)."\n",
        );
    }

    private function removeArtifacts(): void
    {
        foreach ([$this->ledgerPath, $this->placementFilePath] as $path) {
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
