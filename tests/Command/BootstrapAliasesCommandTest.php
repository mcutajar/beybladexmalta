<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeStanding;
use App\Entity\PlayerAliasSource;
use App\Entity\Tournament;
use App\Service\ChallongeSnapshotFiles;
use App\Service\ChallongeSnapshotWriter;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * Seeding the alias table out of the league's own history.
 *
 * The evidence the pass runs on is real — rank *n* of a captured bracket is
 * line *n* of the placement list typed at the time — so these tests build the
 * two halves of it: an event with a finishing order, and a snapshot that ranks
 * the same people under different spellings.
 *
 * What is being pinned down is mostly what it refuses. It writes nothing until
 * told twice, nothing two events disagree about, nothing already true, nothing
 * that would point one blader's name at another, and nothing at all out of a
 * team event.
 */
#[ResetDatabase]
final class BootstrapAliasesCommandTest extends ConsoleTestCase
{
    /**
     * @var list<string> the snapshots a test captured, so they can be deleted
     */
    private array $captured = [];

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:bootstrap-aliases';
    }

    public function testItReadsASpellingOutOfAnEventAlreadyImported(): void
    {
        $this->event('Gamesplus 16-08', 'aaaa1111', imported: ['Lanzjan'], ranked: ['Anzjan']);

        $tester = $this->bootstrap();

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'Lanzjan');
        self::assertCommandSaid($tester, 'Anzjan');
        self::assertCommandSaid($tester, '1 to write, 0 already on file, 0 that cannot be filed.');
    }

    /**
     * The whole reason it is dry by default. Sixty assertions about who is who
     * are unpleasant to unpick, so the first run only ever prints.
     */
    public function testItWritesNothingUntilItIsForced(): void
    {
        $this->event('Gamesplus 16-08', 'aaaa1111', imported: ['Lanzjan'], ranked: ['Anzjan']);

        $tester = $this->bootstrap();

        self::assertCommandSaid($tester, 'Nothing was written. Run it again with --force to file 1 alias.');

        PlayerAliasFactory::assert()->count(0);
        self::assertLedgerIsEmpty();
    }

    public function testItFilesWhatItProposedWhenForced(): void
    {
        $this->event('Gamesplus 16-08', 'aaaa1111', imported: ['Lanzjan'], ranked: ['Anzjan']);

        $tester = $this->bootstrap(force: true);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '1 alias was filed.');

        PlayerAliasFactory::assert()->exists([
            'alias' => 'Anzjan',
            'normalised' => 'anzjan',
            'source' => PlayerAliasSource::Seeded,
        ]);
    }

    /**
     * A seeded alias is in the ledger like any other admin action, and marked
     * as seeded — so a rebuilt database gets it back, and the record of which
     * aliases nobody actually looked at survives.
     */
    public function testEverySeededAliasIsReplayable(): void
    {
        $this->event('Gamesplus 16-08', 'aaaa1111', imported: ['Lanzjan'], ranked: ['Anzjan']);

        $this->bootstrap(force: true);

        self::assertLedgerRecordsAlias('Lanzjan', 'Anzjan', PlayerAliasSource::Seeded);
    }

    public function testRunningItTwiceChangesNothing(): void
    {
        $this->event('Gamesplus 16-08', 'aaaa1111', imported: ['Lanzjan'], ranked: ['Anzjan']);

        $this->bootstrap(force: true);
        $tester = $this->bootstrap(force: true);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'Nothing to write. The alias table already says everything the imports do.');
        self::assertCommandSaid($tester, 'already on file');

        PlayerAliasFactory::assert()->count(1);
    }

    /**
     * Case, punctuation and an invitation nobody accepted fold away on their
     * own, so a row for one of them would be a row the resolver never reads.
     */
    public function testItLearnsNothingFromASpellingThatAlreadyFolds(): void
    {
        $this->event('Gamesplus 12-07', 'aaaa1111', imported: ['Markinu'], ranked: ['MARKINU (invitation pending)']);

        $tester = $this->bootstrap(force: true);

        self::assertCommandSaid($tester, 'Nothing was learned.');

        PlayerAliasFactory::assert()->count(0);
    }

    /**
     * Two evenings, two bladers, one spelling. Picking either would file half
     * of somebody's career under a name that then resolves silently for ever.
     */
    public function testItRefusesASpellingTwoEventsDisagreeAbout(): void
    {
        $this->event('Gamebreaker 04 July', 'aaaa1111', imported: ['Obelix'], ranked: ['Obelisk']);
        $this->event('Gamebreaker 25-07', 'bbbb2222', imported: ['Butcher'], ranked: ['Obelisk']);

        $tester = $this->bootstrap(force: true);

        self::assertCommandSaid($tester, 'Spellings two evenings disagree about');
        self::assertCommandSaid($tester, '"Obelisk" is Obelix in Gamebreaker 04 July; and Butcher in Gamebreaker 25-07.');

        PlayerAliasFactory::assert()->count(0);
    }

    /**
     * A spelling that is somebody else's actual name is a merge to think about
     * rather than an alias to file, so it is shown and left alone.
     */
    public function testItRefusesToPointOneBladersNameAtAnother(): void
    {
        PlayerFactory::createOne(['name' => 'Obelisk']);

        $this->event('Gamesplus 28-06', 'aaaa1111', imported: ['Obelix'], ranked: ['Obelisk']);

        $tester = $this->bootstrap(force: true);

        self::assertCommandSaid($tester, '0 to write, 0 already on file, 1 that cannot be filed.');
        self::assertCommandSaid($tester, 'another blader\'s name');

        PlayerAliasFactory::assert()->count(0);
    }

    /**
     * The refusal this ticket cares most about.
     *
     * A 2v2 event was imported as a Player A list and a Player B list, so its
     * ranks are teams and its lines are half-teams. Pairing them would file the
     * team name `JG` against whoever was written first — and where the roster
     * was never known the line is not a blader at all but a placeholder: `JG1`,
     * `JG2`, and the literal dashes the lists were padded to ten with. None of
     * those is a person, and none of them is something to learn.
     */
    public function testItReadsNothingOutOfATeamEvent(): void
    {
        $this->event('11 July Gamebreaker 2v2 Player A', 'aaaa1111', imported: ['Markulegend', 'JG1'], ranked: ['mark squared', 'JG']);
        $this->event('11 July Gamebreaker 2v2 Player B', 'aaaa1111', imported: ['Markinu', 'JG2'], ranked: ['mark squared', 'JG']);

        $tester = $this->bootstrap(force: true);

        self::assertCommandSaid($tester, 'it is a 2v2 event: the entrants are teams');
        self::assertCommandSaid($tester, 'Nothing was learned.');

        PlayerAliasFactory::assert()->count(0);
    }

    /**
     * An event that taught it nothing is named. A pass that quietly left one
     * out would look the same whether it had read everything or half of it.
     *
     * The uncaptured bracket is an invented slug, like every other slug here.
     * It used to be a real one, which made this test pass only for as long as
     * nobody captured that bracket — and capturing it is what #55 does to all
     * of them. The failure read as a bug in the command rather than as the
     * corpus moving, which is the worst way for a test to be wrong.
     */
    public function testItSaysWhichEventsItReadNothingOutOf(): void
    {
        $this->importedEvent('Gamesplus 23-08', 'https://challonge.com/cccc3333', ['Giglio']);
        $this->importedEvent('An evening with no bracket', null, ['Giglio']);

        $tester = $this->bootstrap();

        self::assertCommandSaid($tester, 'Events nothing was read out of');
        self::assertCommandSaid($tester, 'its bracket has not been captured yet');
        self::assertCommandSaid($tester, 'it was imported from a placement list alone');
    }

    /**
     * The rule the whole epic rests on, from this end: the pass pairs a
     * spelling with a blader who is already in the results it read, so there is
     * no path through it that invents a seventy-seventh.
     */
    public function testItNeverCreatesABlader(): void
    {
        $this->event('Gamesplus 16-08', 'aaaa1111', imported: ['Lanzjan'], ranked: ['Anzjan']);

        $this->bootstrap(force: true);

        PlayerFactory::assert()->count(1);
    }

    /**
     * The team-event skip has to survive a bracket whose slug is all digits.
     *
     * PHP casts a decimal-integer array key to `int`, and the slugs come back
     * out of `array_count_values()` as keys — so an id-style link would come
     * back as an `int`, miss the strict comparison, and be read as an ordinary
     * event. The whole team event would then be paired rank against line.
     */
    public function testItSkipsATeamEventWhoseBracketIsANumericId(): void
    {
        $this->event('11 July Gamebreaker 2v2 Player A', '12345678', imported: ['Markulegend', 'JG1'], ranked: ['mark squared', 'JG']);
        $this->event('11 July Gamebreaker 2v2 Player B', '12345678', imported: ['Markinu', 'JG2'], ranked: ['mark squared', 'JG']);

        $tester = $this->bootstrap(force: true);

        self::assertCommandSaid($tester, 'it is a 2v2 event: the entrants are teams');
        self::assertCommandSaid($tester, 'Nothing was learned.');

        PlayerAliasFactory::assert()->count(0);
    }

    /**
     * Two entrants at one rank means line *n* and rank *n* are not the same
     * claim, so the event is left alone rather than half-read.
     *
     * The tie here is hidden behind a row that names nobody — unjoined, with
     * no entrant name and no linked account. Such a row stores no name, so a
     * guard that only watched the names it kept would never see the rank twice
     * and would pair the surviving entrant with whoever was imported there.
     */
    public function testItRefusesAnEventWhereTwoEntrantsShareARank(): void
    {
        $this->captureStandings('aaaa1111', [
            ['rank' => 1, 'name' => 'Giglio'],
            ['rank' => 2, 'name' => null],
            ['rank' => 2, 'name' => 'Obelisk'],
        ]);

        $this->importedEvent('Gamesplus 28-06', 'https://challonge.com/aaaa1111', ['Giglio', 'Obelix']);

        $tester = $this->bootstrap(force: true);

        self::assertCommandSaid($tester, 'two entrants share a rank');
        self::assertCommandSaid($tester, 'Nothing was learned.');

        PlayerAliasFactory::assert()->count(0);
    }

    /**
     * Every alias is its own flush and its own ledger line, in its own
     * transaction, so a ledger that stops accepting writes part way through
     * leaves the rows before it committed. The run stops there and says how
     * many landed — an operator told nothing happened would go looking for the
     * wrong problem, and an alias with no ledger line does not survive the next
     * rebuild.
     */
    public function testItStopsWhenTheLedgerStopsAcceptingWrites(): void
    {
        $this->event('Gamesplus 16-08', 'aaaa1111', imported: ['Lanzjan'], ranked: ['Anzjan']);
        $this->event('Gamesplus 26-07', 'bbbb2222', imported: ['Belti'], ranked: ['IlBelti']);

        self::blockLedgerWrites();

        $tester = $this->bootstrap(force: true);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, '0 aliases were filed before the recovery ledger stopped accepting writes.');

        PlayerAliasFactory::assert()->count(0);
    }

    private function bootstrap(bool $force = false): CommandTester
    {
        return $this->executeCommand($force ? ['--force' => true] : []);
    }

    /**
     * An event as the league has it: a placement list imported under our own
     * names, and the bracket it was typed from, captured.
     *
     * @param list<string> $imported the finishing order as it was typed, best first
     * @param list<string> $ranked   the same finishing order as the bracket spells it
     */
    private function event(string $title, string $slug, array $imported, array $ranked): Tournament
    {
        $this->captureBracket($slug, $ranked);

        return $this->importedEvent($title, 'https://challonge.com/'.$slug, $imported);
    }

    /**
     * @param list<string> $imported
     */
    private function importedEvent(string $title, ?string $challongeUrl, array $imported): Tournament
    {
        $tournament = TournamentFactory::createOne([
            'title' => $title,
            'challongeUrl' => $challongeUrl,
            'heldOn' => new \DateTimeImmutable(sprintf('2026-01-%02d', count($this->captured) + count($imported))),
        ]);

        foreach ($imported as $position => $name) {
            TournamentResultFactory::createOne([
                'tournament' => $tournament,
                'player' => PlayerFactory::findOrCreate(['name' => $name]),
                'rank' => $position + 1,
            ]);
        }

        return $tournament;
    }

    /**
     * @param list<string> $entrants in finishing order
     */
    private function captureBracket(string $slug, array $entrants): void
    {
        $this->captureStandings($slug, array_map(
            static fn (int $position, string $name): array => ['rank' => $position + 1, 'name' => $name],
            array_keys($entrants),
            $entrants,
        ));
    }

    /**
     * Writes a snapshot the same way `app:fetch-challonge` does, with the
     * standings rows carrying no match history — which is what a one-stage
     * bracket looks like, and what makes the join fall back to the name.
     *
     * A row with no name is a row that named nobody: unjoined, with neither an
     * entrant name nor a linked account, which is the shape `ChallongePlacing`
     * answers `null` for.
     *
     * @param list<array{rank: int, name: ?string}> $rows
     */
    private function captureStandings(string $slug, array $rows): void
    {
        if (in_array($slug, $this->captured, true)) {
            return;
        }

        $participants = [];
        $standings = [];

        foreach ($rows as $position => $row) {
            if (null !== $row['name']) {
                $participants[] = new ChallongeParticipant(
                    id: $position + 1,
                    participantId: null,
                    seed: $position + 1,
                    name: $row['name'],
                );
            }

            $standings[] = new ChallongeStanding(
                rank: $row['rank'],
                name: $row['name'],
                challongeUser: null,
                labels: [],
                matchIds: [],
                columns: [],
            );
        }

        $this->service(ChallongeSnapshotWriter::class)->write(new ChallongeSnapshot(
            slug: $slug,
            sourceUrl: sprintf('https://challonge.com/%s/module?show_standings=1', $slug),
            fetchedAt: new \DateTimeImmutable('2026-08-25T12:00:00+00:00'),
            tournamentId: 1,
            tournamentType: 'swiss',
            tournamentState: 'complete',
            isTeamTournament: false,
            stages: [new ChallongeStage(
                kind: ChallongeStageKind::Single,
                name: null,
                format: 'swiss',
                rounds: [],
                participants: $participants,
                matches: [],
                standings: $standings,
            )],
        ));

        $this->captured[] = $slug;
    }

    /**
     * `var/data/challonge/` is tracked by git and holds the real brackets, so a
     * test that captures one has to take it away again.
     */
    #[\Override]
    protected function artifactPaths(): array
    {
        return [
            ...parent::artifactPaths(),
            ...array_map(
                fn (string $slug): string => $this->service(ChallongeSnapshotFiles::class)->pathFor($slug),
                $this->captured,
            ),
        ];
    }
}
