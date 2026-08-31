<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

/**
 * `app:import-tournament … --unranked`, which is the line the ledger writes
 * and `repeat.sh` replays.
 *
 * Two rules the replay contract turns on, and both are asserted here because
 * neither can be recovered from afterwards: `--season` and `--unranked` are
 * mutually exclusive, and **omitting both is an error**. A line that quietly
 * defaulted to unranked would rebuild a scored event as an unscored one, and
 * the ledger is the only record either of them has.
 */
#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class UnrankedImportCommandTest extends ConsoleTestCase
{
    private const TITLE = 'Malta International Exhibition';

    private const DATE = '2026-08-02';

    private const PLACEMENTS = ['Giglio', 'Obelix', 'Lanzjan'];

    private string $placementFilePath;

    public function testItOpensAnEventThatBelongsToNoSeasonAndScoresNothing(): void
    {
        $this->writePlacementFile(self::PLACEMENTS);

        $tester = $this->importUnranked();

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid(
            $tester,
            'Successfully imported "Malta International Exhibition" as an unranked tournament.'
            .' Archived 3 placings and scored nothing.',
        );

        TournamentFactory::assert()->count(1);
        TournamentResultFactory::assert()->empty();

        $event = self::findTournament(self::TITLE);

        self::assertNull($event->getSeason());
        self::assertFalse($event->isRanked());
        self::assertSame(self::DATE, $event->getHeldOn()->format('Y-m-d'));
    }

    /**
     * An unranked import invents nobody, and that is the invariant rather than
     * an omission.
     *
     * A ranked import creates a blader for every unknown name because it has
     * to write a `TournamentResult` against one. An unranked import writes no
     * result, so it has no reason to — and the rule that a name nobody
     * recognises is a question rather than a new row is the one the whole
     * import epic turns on.
     *
     * Nothing is lost by it. The bladers of an unranked event come from the
     * `app:create-blader` and `app:alias` lines the preview appends *before*
     * the import, and it is the archive after it that attaches its entrants to
     * them. The placement file is the record of the finishing order rather
     * than an input that scores.
     */
    public function testAnUnrankedImportInventsNobody(): void
    {
        $this->writePlacementFile(self::PLACEMENTS);

        self::assertCommandExited($this->importUnranked(), Command::SUCCESS);

        PlayerFactory::assert()->empty();
    }

    public function testItWritesTheUnrankedLineToTheLedger(): void
    {
        $this->writePlacementFile(self::PLACEMENTS);

        self::assertCommandExited($this->importUnranked(), Command::SUCCESS);

        self::assertLedgerRecordsImport(
            title: self::TITLE,
            heldOn: self::DATE,
            sourcePath: $this->placementFilePath,
            seasonSlug: null,
        );
    }

    public function testSeasonAndUnrankedCannotBeUsedTogether(): void
    {
        $this->writePlacementFile(self::PLACEMENTS);

        $tester = $this->execute(['--season' => 'paid-season', '--unranked' => true]);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'cannot be used together');
        self::assertNoEventWasImported();
        self::assertLedgerIsEmpty();
    }

    /**
     * Never a default. Silence about whether an event scores is the one thing
     * this command may not resolve on its own.
     */
    public function testOmittingBothIsAnErrorRatherThanUnranked(): void
    {
        $this->writePlacementFile(self::PLACEMENTS);

        $tester = $this->execute();

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'One of --season or --unranked is required.');
        self::assertNoEventWasImported();
        self::assertLedgerIsEmpty();
    }

    public function testATeamEventCannotBeUnranked(): void
    {
        $this->writePlacementFile(['irmied u gebel: Butcher + Obelix']);

        $tester = $this->execute(['--unranked' => true, '--team' => true]);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertNoEventWasImported();
    }

    /**
     * A ranked replay is byte-for-byte what it was, which is the promise every
     * line already in `repeat.sh` depends on.
     */
    public function testARankedReplayIsUnchanged(): void
    {
        $this->writePlacementFile(self::PLACEMENTS);

        self::assertCommandExited($this->execute(['--season' => 'paid-season']), Command::SUCCESS);

        $event = self::findTournament(self::TITLE);

        self::assertTrue($event->isRanked());
        self::assertSame(SeasonStory::paymentSeason()->getSlug(), $event->getSeason()?->getSlug());
        TournamentResultFactory::assert()->count(3);
        self::assertPlacementsScoredInOrder($event, self::PLACEMENTS);
    }

    #[\Override]
    protected function setUp(): void
    {
        $this->placementFilePath = sprintf(
            '%s/unranked-placements-%s.txt',
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

    private function importUnranked(): CommandTester
    {
        return $this->execute(['--unranked' => true]);
    }

    /**
     * @param array<string, bool|string> $overrides
     */
    private function execute(array $overrides = []): CommandTester
    {
        return $this->executeCommand(array_merge([
            'title' => self::TITLE,
            'date' => self::DATE,
            'file' => $this->placementFilePath,
        ], $overrides));
    }

    /**
     * @param list<string> $lines
     */
    private function writePlacementFile(array $lines): void
    {
        file_put_contents($this->placementFilePath, implode("\n", $lines)."\n");
    }
}
