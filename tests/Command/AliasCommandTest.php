<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\PlayerAliasSource;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The alias table from the shell, and the two things a replay depends on: an
 * alias that is written is in the ledger, and an alias that is not written
 * leaves no line behind.
 */
#[ResetDatabase]
final class AliasCommandTest extends ConsoleTestCase
{
    #[\Override]
    protected static function commandName(): string
    {
        return 'app:alias';
    }

    public function testItRecordsASpellingAgainstABlader(): void
    {
        PlayerFactory::createOne(['name' => 'Lanzjan']);

        $tester = $this->add('Lanzjan', 'Anzjan');

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'Lanzjan answers to "Anzjan".');

        PlayerAliasFactory::assert()->exists([
            'alias' => 'Anzjan',
            'normalised' => 'anzjan',
            'source' => PlayerAliasSource::Manual,
        ]);
    }

    public function testItLogsAReplayableLedgerEntry(): void
    {
        PlayerFactory::createOne(['name' => 'Il-Karm']);

        $this->add('Il-Karm', 'KARM');

        self::assertLedgerRecordsAlias('Il-Karm', 'KARM');
    }

    /**
     * A rebuilt database is repeat.sh replayed from nothing, and #51 will put
     * sixty seeded aliases into it. A seeded line has to come back as seeded,
     * or the record of which aliases nobody actually looked at is lost the
     * first time the schema changes.
     */
    public function testTheLedgerRemembersWhereAnAliasCameFrom(): void
    {
        PlayerFactory::createOne(['name' => 'Guzman93']);

        $tester = $this->add('Guzman93', 'GUZMAN', PlayerAliasSource::Seeded);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertLedgerRecordsAlias('Guzman93', 'GUZMAN', PlayerAliasSource::Seeded);

        PlayerAliasFactory::assert()->exists(['source' => PlayerAliasSource::Seeded]);
    }

    /**
     * The blader is written under the name the database holds rather than the
     * one that was typed, so a replay files the alias against the same person
     * however the original command spelled them.
     */
    public function testTheLedgerNamesTheBladerTheDatabaseHolds(): void
    {
        PlayerFactory::createOne(['name' => 'Il-Karm']);

        $this->add('IL_KARM', 'KARM');

        self::assertLedgerRecordsAlias('Il-Karm', 'KARM');
    }

    /**
     * The rule the whole ticket exists for. A name nobody recognises is a
     * question, and the answer is never a seventy-seventh blader.
     */
    public function testItRefusesToInventABlader(): void
    {
        PlayerFactory::createOne(['name' => 'Il-Karm']);

        $tester = $this->add('Karmy', 'karmz');

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'There is no blader called "Karmy", and an alias never creates one.');

        PlayerFactory::assert()->count(1);
        PlayerAliasFactory::assert()->empty();
        self::assertLedgerIsEmpty();
    }

    /**
     * Offered, and left for somebody to act on.
     */
    public function testItOffersWhoTheNameMightHaveBeen(): void
    {
        PlayerFactory::createOne(['name' => 'Obelix']);

        $tester = $this->add('Obelisk', 'Obelisk');

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'Did you mean:');
        self::assertCommandSaid($tester, 'Obelix — 2 edits from "obelix"');

        PlayerAliasFactory::assert()->empty();
    }

    /**
     * Aliases and blader names are one namespace, so nothing downstream ever
     * has to decide which of the two wins.
     */
    public function testItRefusesASpellingThatIsAnotherBladersName(): void
    {
        PlayerFactory::createOne(['name' => 'Obelix']);
        PlayerFactory::createOne(['name' => 'Lanzjan']);

        $tester = $this->add('Obelix', 'LANZJAN');

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'that is a merge rather than an alias');

        PlayerAliasFactory::assert()->empty();
        self::assertLedgerIsEmpty();
    }

    public function testItRefusesASpellingAnotherBladerAlreadyAnswersTo(): void
    {
        PlayerFactory::createOne(['name' => 'Il-Karm']);
        $obelix = PlayerFactory::createOne(['name' => 'Obelix']);

        PlayerAliasFactory::createOne(['player' => $obelix, 'alias' => 'KARM']);

        $tester = $this->add('Il-Karm', 'karm');

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, '"karm" already points at Obelix.');

        PlayerAliasFactory::assert()->count(1);
        self::assertLedgerIsEmpty();
    }

    /**
     * repeat.sh is replayed whole, and `app:import-tournament` doubling its
     * rows on a second replay is a known trap. This one does not: an alias
     * already on file against the same blader is a no-op that reports itself
     * and writes nothing.
     */
    public function testReplayingTheSameLineChangesNothing(): void
    {
        PlayerFactory::createOne(['name' => 'Il-Karm']);

        $this->add('Il-Karm', 'KARM');
        $tester = $this->add('Il-Karm', 'KARM');

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '"KARM" was already on file against Il-Karm.');

        PlayerAliasFactory::assert()->count(1);
        self::assertLedgerRecordsAlias('Il-Karm', 'KARM');
    }

    public function testASpellingABladerAlreadyAnswersToIsNotRecordedTwice(): void
    {
        PlayerFactory::createOne(['name' => 'Lanzjan']);

        $tester = $this->add('Lanzjan', 'l-anzjan');

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'so it already resolves. Nothing was recorded.');

        PlayerAliasFactory::assert()->empty();
        self::assertLedgerIsEmpty();
    }

    public function testItRefusesAStringWithNoNameInIt(): void
    {
        PlayerFactory::createOne(['name' => 'Lanzjan']);

        $tester = $this->add('Lanzjan', '(invitation pending)');

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'has no name in it once case, punctuation');

        PlayerAliasFactory::assert()->empty();
    }

    /**
     * The ledger write happens inside the flush transaction, so an alias can
     * never outlive the line that would replay it.
     */
    public function testAFailedLedgerWriteLeavesNoAlias(): void
    {
        PlayerFactory::createOne(['name' => 'Il-Karm']);

        self::blockLedgerWrites();

        $tester = $this->add('Il-Karm', 'KARM');

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'The alias table was left alone because the recovery ledger could not be updated.');

        PlayerAliasFactory::assert()->empty();
    }

    public function testItListsWhatIsOnFile(): void
    {
        $karm = PlayerFactory::createOne(['name' => 'Il-Karm']);

        PlayerAliasFactory::createOne(['player' => $karm, 'alias' => 'KARM']);

        $tester = $this->executeCommand(['action' => 'list']);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'Il-Karm KARM karm manual');
        self::assertCommandSaid($tester, '1 alias on file.');
    }

    public function testItListsOneBladersSpellings(): void
    {
        $karm = PlayerFactory::createOne(['name' => 'Il-Karm']);
        $obelix = PlayerFactory::createOne(['name' => 'Obelix']);

        PlayerAliasFactory::createOne(['player' => $karm, 'alias' => 'KARM']);
        PlayerAliasFactory::createOne(['player' => $obelix, 'alias' => 'OBELIX2']);

        $tester = $this->executeCommand(['action' => 'list', 'names' => ['Il-Karm']]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '1 alias on file.');
        self::assertStringNotContainsString('OBELIX2', $tester->getDisplay());
    }

    public function testAnEmptyTableSaysSo(): void
    {
        $tester = $this->executeCommand(['action' => 'list']);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'No aliases are on file.');
    }

    public function testItRemovesASpelling(): void
    {
        $karm = PlayerFactory::createOne(['name' => 'Il-Karm']);

        PlayerAliasFactory::createOne(['player' => $karm, 'alias' => 'KARM']);

        $tester = $this->executeCommand(['action' => 'remove', 'names' => ['KARM']]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '"KARM" no longer resolves to anybody.');

        PlayerAliasFactory::assert()->empty();
        self::assertLedgerRecordsAliasRemoval('KARM');
    }

    public function testRemovingSomethingThatWasNeverThereWritesNothing(): void
    {
        $tester = $this->executeCommand(['action' => 'remove', 'names' => ['KARM']]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '"KARM" was not on file. Nothing was removed.');

        self::assertLedgerIsEmpty();
    }

    public function testItNamesTheActionsItKnows(): void
    {
        $tester = $this->executeCommand(['action' => 'frobnicate']);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'The actions are: add, list, remove.');
    }

    public function testAddingTakesABladerAndASpelling(): void
    {
        $tester = $this->executeCommand(['action' => 'add', 'names' => ['Lanzjan']]);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'Adding an alias takes the blader and then the spelling');
    }

    public function testItNamesTheSourcesItKnows(): void
    {
        PlayerFactory::createOne(['name' => 'Lanzjan']);

        $tester = $this->executeCommand([
            'action' => 'add',
            'names' => ['Lanzjan', 'Anzjan'],
            '--source' => 'hearsay',
        ]);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'The sources are: manual, seeded, challonge-account.');

        PlayerAliasFactory::assert()->empty();
    }

    private function add(
        string $bladerName,
        string $spelling,
        ?PlayerAliasSource $source = null,
    ): CommandTester {
        $input = ['action' => 'add', 'names' => [$bladerName, $spelling]];

        if (null !== $source) {
            $input['--source'] = $source->value;
        }

        return $this->executeCommand($input);
    }
}
