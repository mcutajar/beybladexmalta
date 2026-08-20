<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Factory\PlayerFactory;
use App\Factory\SeasonFactory;
use App\Factory\TournamentFactory;
use App\Factory\TournamentResultFactory;
use App\Story\SeasonStory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ImportTournamentCommandTest extends ConsoleTestCase
{
    private const TITLE = 'Command Test Cup';

    private const DATE = '2026-08-02';

    private const SEASON = 'paid-season';

    /**
     * Ten distinct bladers, best finish first.
     */
    private const PLACEMENTS = [
        'Giglio', 'Obelix', 'Lanzjan', 'Il-Karm', 'Evilbeys',
        'Derius', 'Rizzler', 'Steve', 'Southboy15', 'Tristan',
    ];

    private string $placementFilePath;

    public function testItImportsAPlacementFile(): void
    {
        $this->writePlacementFile(self::PLACEMENTS);

        $tester = $this->importTournament();

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertCommandSaid(
            $tester,
            'Successfully imported "Command Test Cup" into Paid Season.'
            .' Logged 10 player placements.',
        );

        TournamentFactory::assert()->count(1);
        TournamentResultFactory::assert()->count(10);
        PlayerFactory::assert()->count(10);

        $tournament = self::findTournament(self::TITLE);

        self::assertSame(self::DATE, $tournament->getHeldOn()->format('Y-m-d'));
        self::assertPlacementsScoredInOrder($tournament, self::PLACEMENTS);

        // Nothing in a plain placement file should award a bonus.
        self::assertResultAtRank($tournament, rank: 1, bonusPoints: 0);
    }

    public function testItAppliesManualAndKnockoutBonuses(): void
    {
        $this->writePlacementFile(['Giglio', 'Obelix, 5', 'Lanzjan']);

        $tester = $this->importTournament(['--knockout' => 'obelix']);

        self::assertCommandExited($tester, Command::SUCCESS);

        /*
         * Five bonus points from the file, ten more for taking the knockout
         * bracket, on top of the second place F1 tier.
         */
        self::assertResultAtRank(
            self::findTournament(self::TITLE),
            rank: 2,
            f1Points: 20,
            bonusPoints: 15,
            totalPoints: 35,
        );
    }

    public function testItReusesAnExistingPlayerCaseInsensitively(): void
    {
        PlayerFactory::createOne(['name' => 'Giglio']);

        $this->writePlacementFile(['  gIGLIO  ', 'Obelix']);

        $tester = $this->importTournament();

        self::assertCommandExited($tester, Command::SUCCESS);

        PlayerFactory::assert()->count(2);
        PlayerFactory::assert()->exists(['name' => 'Giglio']);
    }

    public function testItLogsAReplayableLedgerEntry(): void
    {
        $this->writePlacementFile(['Giglio', 'Obelix']);

        $tester = $this->importTournament([
            '--challonge' => 'https://challonge.com/abcd1234',
        ]);

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertLedgerRecordsImport(
            title: self::TITLE,
            heldOn: self::DATE,
            sourcePath: $this->placementFilePath,
            seasonSlug: self::SEASON,
            challongeUrl: 'https://challonge.com/abcd1234',
        );
    }

    public function testItRejectsAnUnreadableFile(): void
    {
        $tester = $this->importTournament();

        self::assertCommandExited($tester, Command::FAILURE);

        self::assertCommandSaid($tester, sprintf(
            'File path "%s" is unreadable or does not exist.',
            $this->placementFilePath,
        ));

        self::assertNothingWasImported();
        self::assertLedgerIsEmpty();
    }

    public function testItRejectsALooselyFormattedDate(): void
    {
        $this->writePlacementFile(['Giglio']);

        $tester = $this->importTournament(['date' => '02/08/2026']);

        self::assertCommandExited($tester, Command::FAILURE);

        self::assertCommandSaid(
            $tester,
            'Invalid date format provided. Please use YYYY-MM-DD.',
        );

        self::assertNothingWasImported();
        self::assertLedgerIsEmpty();
    }

    public function testItCreatesAMissingSeasonOnConfirmation(): void
    {
        $this->writePlacementFile(['Giglio']);

        $tester = $this->importTournament(
            ['--season' => 'brand-new-season'],
            answers: ['yes'],
        );

        self::assertCommandExited($tester, Command::SUCCESS);

        SeasonFactory::assert()->exists([
            'slug' => 'brand-new-season',
            'name' => 'Brand New Season',
        ]);

        TournamentFactory::assert()->count(1);
    }

    public function testItAbortsWhenSeasonCreationIsDeclined(): void
    {
        $this->writePlacementFile(['Giglio']);

        $tester = $this->importTournament(
            ['--season' => 'brand-new-season'],
            answers: ['no'],
        );

        self::assertCommandExited($tester, Command::INVALID);

        SeasonFactory::assert()->notExists(['slug' => 'brand-new-season']);

        self::assertNothingWasImported();
        self::assertLedgerIsEmpty();
    }

    #[\Override]
    protected function setUp(): void
    {
        /*
         * Assigned before the parent's setUp() so that its artifact cleanup
         * can already see the path.
         */
        $this->placementFilePath = sprintf(
            '%s/placements-%s.txt',
            sys_get_temp_dir(),
            bin2hex(random_bytes(6)),
        );

        parent::setUp();
    }

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:import-tournament';
    }

    #[\Override]
    protected function artifactPaths(): array
    {
        return [...parent::artifactPaths(), $this->placementFilePath];
    }

    /**
     * @param array<string, string> $overrides arguments and options to replace
     * @param list<string>          $answers   replies to interactive questions
     */
    private function importTournament(
        array $overrides = [],
        array $answers = [],
    ): CommandTester {
        return $this->executeCommand(array_merge([
            'title' => self::TITLE,
            'date' => self::DATE,
            'file' => $this->placementFilePath,
            '--season' => self::SEASON,
        ], $overrides), $answers);
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
}
