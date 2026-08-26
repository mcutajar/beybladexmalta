<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Tournament;
use App\Entity\TournamentTeam;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Factory\TournamentTeamFactory;
use App\Tests\Factory\TournamentTeamMemberFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class TeamCommandTest extends ConsoleTestCase
{
    private const EVENT = '11 July Gamebreaker 2v2';

    public function testItClaimsAnUnclaimedTeam(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);
        PlayerFactory::createOne(['name' => 'Kane']);
        PlayerFactory::createOne(['name' => 'Steve']);

        $tester = $this->executeCommand([
            'action' => 'claim',
            'names' => [self::EVENT, 'JG', 'Kane', 'Steve'],
        ]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'JG finished 10th at "11 July Gamebreaker 2v2", and now so do Kane and Steve.');

        self::assertTeamAtRank($event, rank: 10, name: 'JG', bladers: ['Kane', 'Steve']);
    }

    /**
     * The claim is what turns a kept placing into points, weeks after the
     * event was imported.
     */
    public function testItWritesThePlacementsTheTeamNeverHad(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);
        PlayerFactory::createOne(['name' => 'Kane']);
        PlayerFactory::createOne(['name' => 'Steve']);

        $this->claim([self::EVENT, 'JG', 'Kane', 'Steve']);

        self::assertResultAtRank($event, rank: 10, f1Points: 1, bonusPoints: 0, totalPoints: 1);

        self::assertSame(2, TournamentResultFactory::repository()->count([
            'tournament' => $event,
            'rank' => 10,
        ]));
    }

    public function testItLogsAReplayableLedgerEntry(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);
        PlayerFactory::createOne(['name' => 'Kane']);

        $this->claim([self::EVENT, 'JG', 'Kane']);

        self::assertLedgerRecordsTeamClaim(self::EVENT, 'JG', ['Kane']);
    }

    /**
     * A replay of the ledger line above must not attach anybody twice, nor
     * award the rank's points a second time.
     */
    public function testClaimingTheSamePeopleAgainChangesNothing(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);
        PlayerFactory::createOne(['name' => 'Kane']);

        $this->claim([self::EVENT, 'JG', 'Kane']);
        self::removePath(self::ledgerPath());

        $tester = $this->claim([self::EVENT, 'JG', 'Kane']);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'JG was already down as Kane. Nothing was recorded.');

        self::assertSame(1, TournamentResultFactory::repository()->count(['tournament' => $event]));
        self::assertLedgerIsEmpty();
    }

    /**
     * The alias table makes this easy to type — `Obelisk` and `Obelix` are one
     * person, so a claim can reach them under both — and both guards ahead of
     * the write look at what is already on file rather than at what this claim
     * has already reached.
     */
    public function testNamingOneBladerTwiceInOneClaimAttachesThemOnce(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);
        PlayerAliasFactory::createOne([
            'player' => PlayerFactory::createOne(['name' => 'Obelix']),
            'alias' => 'Obelisk',
        ]);

        $tester = $this->claim([self::EVENT, 'JG', 'Obelisk', 'Obelix']);

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertTeamAtRank($event, rank: 10, name: 'JG', bladers: ['Obelix']);

        self::assertSame(1, TournamentResultFactory::repository()->count(['tournament' => $event]));
        self::assertLedgerRecordsTeamClaim(self::EVENT, 'JG', ['Obelix']);
    }

    /**
     * A half-known team is allowed, and the second name arriving later is the
     * same operation run again.
     */
    public function testATeamCanBeClaimedAHalfAtATime(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);
        PlayerFactory::createOne(['name' => 'Kane']);
        PlayerFactory::createOne(['name' => 'Steve']);

        $this->claim([self::EVENT, 'JG', 'Kane']);
        self::removePath(self::ledgerPath());

        self::assertCommandExited($this->claim([self::EVENT, 'JG', 'Kane', 'Steve']), Command::SUCCESS);

        self::assertTeamAtRank($event, rank: 10, name: 'JG', bladers: ['Kane', 'Steve']);
        self::assertLedgerRecordsTeamClaim(self::EVENT, 'JG', ['Steve']);
    }

    /**
     * The rule that separates a claim from an import. An import is typed
     * alongside its event and new people turn up at one; a claim is filed
     * against a league that already knows everybody who was there.
     */
    public function testAClaimNeverCreatesABlader(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);

        $tester = $this->claim([self::EVENT, 'JG', 'Nobody']);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'There is no blader called "Nobody", and a claim never creates one.');

        PlayerFactory::assert()->notExists(['name' => 'Nobody']);
        self::assertTeamIsUnclaimed($event, 'JG');
        self::assertLedgerIsEmpty();
    }

    /**
     * The alias table is what a claim resolves through, so a team can be
     * claimed under the spelling the bracket used.
     */
    public function testItClaimsThroughTheAliasTable(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);
        PlayerAliasFactory::createOne([
            'player' => PlayerFactory::createOne(['name' => 'Obelix']),
            'alias' => 'Obelisk',
        ]);

        self::assertCommandExited($this->claim([self::EVENT, 'JG', 'Obelisk']), Command::SUCCESS);

        self::assertTeamAtRank($event, rank: 10, name: 'JG', bladers: ['Obelix']);
        self::assertLedgerRecordsTeamClaim(self::EVENT, 'JG', ['Obelix']);
    }

    public function testAClaimNeverCreatesATeam(): void
    {
        $this->teamEvent();
        PlayerFactory::createOne(['name' => 'Kane']);

        $tester = $this->claim([self::EVENT, 'nobody was called this', 'Kane']);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'had no entrant called "nobody was called this"');

        TournamentTeamFactory::assert()->empty();
        self::assertLedgerIsEmpty();
    }

    /**
     * Somebody already on the board played for another entrant, and a second
     * placement would score them twice for one evening.
     */
    public function testABladerCannotFinishTwiceInOneEvent(): void
    {
        $event = $this->teamEvent();
        $this->unclaimed($event, 'JG', rank: 10);

        $butcher = PlayerFactory::createOne(['name' => 'Butcher']);
        TournamentResultFactory::createOne([
            'tournament' => $event,
            'player' => $butcher,
            'rank' => 1,
            'f1Points' => 25,
            'bonusPoints' => 0,
        ]);

        $tester = $this->claim([self::EVENT, 'JG', 'Butcher']);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Butcher already finished at "11 July Gamebreaker 2v2" for another team.');

        self::assertTeamIsUnclaimed($event, 'JG');
        self::assertLedgerIsEmpty();
    }

    public function testItRefusesAnEventItCannotName(): void
    {
        $tester = $this->claim(['No Such Cup', 'JG', 'Kane']);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'There is no event called "No Such Cup".');
    }

    public function testItRefusesATitleTwoEventsShare(): void
    {
        $this->teamEvent();
        $this->teamEvent();
        PlayerFactory::createOne(['name' => 'Kane']);

        $tester = $this->claim([self::EVENT, 'JG', 'Kane']);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'More than one event is called "11 July Gamebreaker 2v2"');
    }

    public function testClaimingNeedsTheEventTheTeamAndABlader(): void
    {
        $tester = $this->claim([self::EVENT, 'JG']);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'Claiming a team takes the event, the team and then the bladers in it');
    }

    public function testItListsOneEventsTeamsAndCountsTheUnclaimed(): void
    {
        $event = $this->teamEvent();
        $this->claimed($event, 'irmied u gebel', rank: 1, bladers: ['Butcher', 'Obelix']);
        $this->unclaimed($event, 'JG', rank: 10);

        $tester = $this->executeCommand([
            'action' => 'list',
            'names' => [self::EVENT],
        ]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'irmied u gebel');
        self::assertCommandSaid($tester, 'unclaimed');
        self::assertCommandSaid($tester, '2 teams, 1 unclaimed.');
    }

    public function testListingAnEventWithNoTeamsSaysSo(): void
    {
        $this->teamEvent();

        $tester = $this->executeCommand([
            'action' => 'list',
            'names' => [self::EVENT],
        ]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '"11 July Gamebreaker 2v2" is not a team event.');
    }

    public function testItRejectsAnActionItDoesNotHave(): void
    {
        $tester = $this->executeCommand(['action' => 'unclaim', 'names' => []]);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, '"unclaim" is not something this does.');
    }

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:team';
    }

    /**
     * @param list<string> $names
     */
    private function claim(array $names): CommandTester
    {
        return $this->executeCommand(['action' => 'claim', 'names' => $names]);
    }

    private function teamEvent(): Tournament
    {
        return TournamentFactory::createOne([
            'title' => self::EVENT,
            'season' => SeasonStory::paymentSeason(),
        ]);
    }

    private function unclaimed(Tournament $event, string $name, int $rank): TournamentTeam
    {
        return TournamentTeamFactory::createOne([
            'tournament' => $event,
            'name' => $name,
            'rank' => $rank,
        ]);
    }

    /**
     * @param list<string> $bladers
     */
    private function claimed(Tournament $event, string $name, int $rank, array $bladers): void
    {
        $team = $this->unclaimed($event, $name, $rank);

        foreach ($bladers as $blader) {
            TournamentTeamMemberFactory::createOne([
                'team' => $team,
                'player' => PlayerFactory::createOne(['name' => $blader]),
            ]);
        }
    }
}
