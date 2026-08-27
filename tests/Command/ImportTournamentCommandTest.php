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
            '--challonge' => 'https://worldbeyblade.challonge.com/co5nncw8',
        ]);

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertLedgerRecordsImport(
            title: self::TITLE,
            heldOn: self::DATE,
            sourcePath: $this->placementFilePath,
            seasonSlug: self::SEASON,
            challongeUrl: 'https://worldbeyblade.challonge.com/co5nncw8',
            snapshotPath: self::projectDir().'/var/data/challonge/co5nncw8.json',
        );

        self::assertNotEmpty(
            self::findTournament(self::TITLE)->getStages(),
            'The archive staged by the replay workflow was not flushed with the tournament.',
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

    public function testItRejectsAMissingSeasonWithoutPrompting(): void
    {
        $this->writePlacementFile(['Giglio']);

        $tester = $this->importTournament(
            ['--season' => 'brand-new-season'],
        );

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Season "brand-new-season" does not exist.');

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
            snapshotPath: self::projectDir().'/var/data/challonge/uhxii7az.json',
        );
    }

    /**
     * `bye` parses as a line and is dropped as an entrant, so this gets past
     * the command's own emptiness check and is refused by the service. Both
     * answers are the same condition, so both are INVALID.
     */
    public function testARosterOfNothingButAByeImportsNobody(): void
    {
        $this->writePlacementFile(['bye']);

        $tester = $this->importTournament(['--team' => true]);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'There is nothing in that file to import.');

        self::assertNothingWasImported();
        self::assertLedgerIsEmpty();
    }

    /**
     * Nobody is meant to enter twice and the league does not sanction it, but
     * the roster is the record of who played with whom — so both places are
     * kept and only the better rank is scored. Awarding both would pay
     * somebody 25 + 20 for one evening.
     */
    public function testABladerInTwoTeamsKeepsBothPlacesAndScoresTheBetterFinish(): void
    {
        PlayerFactory::createOne(['name' => 'Butcher']);

        $this->writePlacementFile([
            'alpha: Butcher + Obelix',
            'beta: Privv + Butcher',
        ]);

        $tester = $this->importTournament(['--team' => true]);

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertCommandSaid(
            $tester,
            'Butcher is in more than one team. Every place is on record, but only the better finish is scored.',
        );

        self::assertCommandSaid($tester, 'Logged 3 player placements across 2 teams.');

        $tournament = self::findTournament(self::TITLE);

        self::assertTeamAtRank($tournament, rank: 1, name: 'alpha', bladers: ['Butcher', 'Obelix']);
        self::assertTeamAtRank($tournament, rank: 2, name: 'beta', bladers: ['Privv', 'Butcher']);

        self::assertSame(1, TournamentResultFactory::repository()->count([
            'tournament' => $tournament,
            'player' => PlayerFactory::find(['name' => 'Butcher']),
        ]));

        self::assertResultAtRank($tournament, rank: 1, f1Points: 25);
    }

    /**
     * The same blader in two entrants, spelled two ways, and unknown to the
     * league until this import. Resolving them separately would build two
     * `Player` rows and die on the unique index rather than say anything.
     */
    public function testTwoSpellingsOfOneNewBladerAreOnePerson(): void
    {
        $this->writePlacementFile([
            'alpha: butcher + Obelix',
            'beta: Privv + BUTCHER',
        ]);

        self::assertCommandExited($this->importTournament(['--team' => true]), Command::SUCCESS);

        PlayerFactory::assert()->count(3);
        PlayerFactory::assert()->exists(['name' => 'butcher']);
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
