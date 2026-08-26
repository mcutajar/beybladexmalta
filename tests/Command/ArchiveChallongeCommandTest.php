<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Tournament;
use App\Repository\TournamentStageRepository;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentTeamFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

/**
 * The archive from the shell, against the brackets in `var/data/challonge/`.
 *
 * Real snapshots rather than built ones, because the command's whole job is to
 * find the event a tracked file belongs to: `wc0vkczl` is the smallest of the
 * eighteen — one stage, eleven entrants, twenty-five matches — and
 * `ArchivedBracketsTest` puts the service itself to all of them.
 */
#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ArchiveChallongeCommandTest extends ConsoleTestCase
{
    private const SLUG = 'wc0vkczl';

    public function testItArchivesTheBracketIntoTheEventItWasImportedFrom(): void
    {
        $event = $this->event();

        $tester = $this->archive(self::SLUG);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'Archived wc0vkczl into "Gamebreaker CX Only 27-06": 1 stage, 11 entrants, 25 matches, no games.');
        self::assertCount(1, $this->service(TournamentStageRepository::class)->forTournament($event));
    }

    public function testItTakesAUrlAsWellAsASlug(): void
    {
        $this->event();

        self::assertCommandExited($this->archive('https://challonge.com/'.self::SLUG), Command::SUCCESS);
    }

    /**
     * Every multi-game match in the corpus is a team match, and team events
     * are not archived, so no captured bracket writes a game.
     */
    public function testTheCapturedBracketsWriteNoGames(): void
    {
        $this->event();

        self::assertCommandSaid($this->archive(self::SLUG), 'no games');
    }

    /**
     * The names come out sorted and quoted so they can be pasted straight into
     * `app:alias add`. In a database with no bladers in it, that is all eleven.
     */
    public function testItNamesTheEntrantsNobodyIsCalled(): void
    {
        $this->event();

        $tester = $this->archive(self::SLUG);

        self::assertCommandSaid($tester, 'Nobody is called "Amanda", "BELTI"');
        self::assertCommandSaid($tester, '0 of the entrants are bladers the league knows.');
    }

    public function testItAttachesTheBladersTheLeagueKnows(): void
    {
        $this->event();
        PlayerFactory::createOne(['name' => 'Amanda']);
        PlayerFactory::createOne(['name' => 'Obelix']);

        self::assertCommandSaid($this->archive(self::SLUG), '2 of the entrants are bladers the league knows.');
    }

    /**
     * A spelling two bladers already answer to is said differently from one
     * nobody answers to, because `app:alias add` cannot settle it — it refuses
     * a spelling that folds onto a blader's own name. Sending an operator there
     * would send them to a refusal.
     */
    public function testItSaysWhenMoreThanOneBladerAnswersToAName(): void
    {
        $this->event();
        PlayerFactory::createOne(['name' => 'Obelix']);
        PlayerAliasFactory::createOne(['player' => PlayerFactory::createOne(['name' => 'Obelisk']), 'alias' => 'Obelix']);

        $tester = $this->archive(self::SLUG);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'More than one blader already answers to a name this bracket used');
        self::assertCommandSaid($tester, '"Obelix" is how more than one blader is already spelled');
        self::assertCommandSaid($tester, 'Two rows for one person is a merge');
    }

    /**
     * The snapshot is tracked by git, so this replays offline — which is the
     * difference between it and `app:fetch-challonge`, which writes no line at
     * all.
     */
    public function testItWritesItsReplayLine(): void
    {
        $this->event();

        $this->archive(self::SLUG);

        self::assertLedgerRecordsArchive(self::SLUG);
    }

    /**
     * An archive is written against the event the bracket produced, so there
     * has to be one.
     */
    public function testItRefusesABracketNoEventWasImportedFrom(): void
    {
        $tester = $this->archive(self::SLUG);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'No event on record was imported from "wc0vkczl".');
        self::assertLedgerIsEmpty();
    }

    /**
     * Nothing stops two events naming one bracket, and picking between them
     * would attach a whole evening's matches to the wrong one.
     */
    public function testItRefusesWhenTwoEventsNameTheSameBracket(): void
    {
        $this->event();
        $this->event('Gamebreaker CX Only 27-06 (again)');

        $tester = $this->archive(self::SLUG);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, '2 events name "wc0vkczl" as their bracket');
    }

    /**
     * A 2v2 event has nothing to archive and that is not a failure: a backfill
     * walks every event, and two of them are team events.
     */
    public function testATeamEventIsNotAFailure(): void
    {
        $event = $this->event();
        TournamentTeamFactory::createOne(['tournament' => $event, 'name' => 'legion', 'rank' => 1]);

        $tester = $this->archive(self::SLUG);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'is a 2v2 event, so nothing but its entrants is archived: 1 entrant on record');
        self::assertLedgerIsEmpty();
    }

    public function testItSaysWhenTheBracketWasNeverCaptured(): void
    {
        $tester = $this->archive('nosuchbracket');

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'Capture the bracket first with app:fetch-challonge.');
    }

    public function testItRejectsSomethingThatIsNotABracket(): void
    {
        $tester = $this->archive('https://example.com/wc0vkczl');

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'is not a Challonge URL');
    }

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:archive-challonge';
    }

    private function event(string $title = 'Gamebreaker CX Only 27-06'): Tournament
    {
        return TournamentFactory::createOne([
            'title' => $title,
            'season' => SeasonStory::freeSeason(),
            'challongeUrl' => 'https://challonge.com/'.self::SLUG,
        ]);
    }

    private function archive(string $bracket): CommandTester
    {
        return $this->executeCommand(['bracket' => $bracket]);
    }
}
