<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Factory\TournamentTeamFactory;
use App\Tests\Story\SeasonStory;
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

    /**
     * The 11 July bracket in miniature: claimed teams, one unclaimed, and the
     * `bye` that is not an entrant at all.
     */
    private const TEAMS = [
        'irmied u gebel: Butcher + Obelix',
        'wakanda forever: Privv + Faenza',
        'JG:',
        'bye',
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

    public function testItImportsATeamEventAsOneTournament(): void
    {
        $this->writePlacementFile(self::TEAMS);

        $tester = $this->importTournament(['--team' => true]);

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertCommandSaid(
            $tester,
            'Successfully imported "Command Test Cup" into Paid Season as a team event.'
            .' Logged 4 player placements across 3 teams.',
        );

        TournamentFactory::assert()->count(1);
        TournamentTeamFactory::assert()->count(3);
        TournamentResultFactory::assert()->count(4);

        $tournament = self::findTournament(self::TITLE);

        self::assertTeamAtRank($tournament, rank: 1, name: 'irmied u gebel', bladers: ['Butcher', 'Obelix']);
        self::assertTeamAtRank($tournament, rank: 2, name: 'wakanda forever', bladers: ['Privv', 'Faenza']);
    }

    /**
     * The entrant's rank becomes each member's rank, so two bladers share a
     * finishing position and the same F1 tier. Nothing in the leaderboard
     * reads rank, and `tournament_results` has no unique index on it.
     */
    public function testEveryBladerInATeamScoresTheTeamsRank(): void
    {
        $this->writePlacementFile(self::TEAMS);

        self::assertCommandExited($this->importTournament(['--team' => true]), Command::SUCCESS);

        $tournament = self::findTournament(self::TITLE);

        self::assertSame(2, TournamentResultFactory::repository()->count([
            'tournament' => $tournament,
            'rank' => 1,
            'f1Points' => 25,
        ]));

        self::assertSame(2, TournamentResultFactory::repository()->count([
            'tournament' => $tournament,
            'rank' => 2,
            'f1Points' => 20,
        ]));
    }

    /**
     * The team existed and finished where it finished. That is a record rather
     * than a gap, so it never blocks the import — and it is the one place the
     * epic's never-auto-create rule resolves to a row instead of a question.
     */
    public function testAnUnclaimedTeamKeepsItsRankAndScoresNothing(): void
    {
        $this->writePlacementFile(self::TEAMS);

        $tester = $this->importTournament(['--team' => true]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '1 of the 3 teams is unclaimed: JG.');

        $tournament = self::findTournament(self::TITLE);

        self::assertTeamIsUnclaimed($tournament, 'JG');
        self::assertSame(3, self::teamCalled($tournament, 'JG')->getRank());

        self::assertSame(0, TournamentResultFactory::repository()->count([
            'tournament' => $tournament,
            'rank' => 3,
        ]));
    }

    /**
     * `bye` is Challonge's own filler, not somebody who turned up — and taking
     * it out must not move anybody, because the ranks are the bracket's.
     */
    public function testItDropsTheByeWithoutRenumberingAroundIt(): void
    {
        $this->writePlacementFile(self::TEAMS);

        self::assertCommandExited($this->importTournament(['--team' => true]), Command::SUCCESS);

        $tournament = self::findTournament(self::TITLE);

        self::assertNoTeamCalled($tournament, 'bye');
        self::assertTeamAtRank($tournament, rank: 3, name: 'JG');
    }

    public function testATeamEventAwardsNoKnockoutBonus(): void
    {
        $this->writePlacementFile(self::TEAMS);

        $tester = $this->importTournament([
            '--team' => true,
            '--knockout' => 'Butcher',
        ]);

        self::assertCommandExited($tester, Command::INVALID);

        self::assertCommandSaid(
            $tester,
            'A team event awards no knockout bonus, so --team and --knockout cannot be used together.',
        );

        self::assertNothingWasImported();
        self::assertLedgerIsEmpty();
    }

    public function testItLogsATeamImportAsReplayable(): void
    {
        $this->writePlacementFile(self::TEAMS);

        $tester = $this->importTournament([
            '--team' => true,
            '--challonge' => 'https://challonge.com/uhxii7az',
        ]);

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertLedgerRecordsImport(
            title: self::TITLE,
            heldOn: self::DATE,
            sourcePath: $this->placementFilePath,
            seasonSlug: self::SEASON,
            challongeUrl: 'https://challonge.com/uhxii7az',
            teamEvent: true,
        );
    }

    public function testARosterOfNothingButAByeImportsNobody(): void
    {
        $this->writePlacementFile(['bye']);

        $tester = $this->importTournament(['--team' => true]);

        self::assertCommandExited($tester, Command::FAILURE);

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
     * @param array<string, bool|string> $overrides arguments and options to replace
     * @param list<string>               $answers   replies to interactive questions
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
