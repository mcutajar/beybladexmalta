<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Factory\PlayerFactory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreateBladerCommandTest extends ConsoleTestCase
{
    #[\Override]
    protected static function commandName(): string
    {
        return 'app:create-blader';
    }

    public function testItPutsABladerOnRecordAndLogsTheReplayLine(): void
    {
        $tester = $this->executeCommand(['name' => '  Sk3lli  ']);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'Sk3lli is on record.');

        PlayerFactory::assert()->count(1);
        PlayerFactory::assert()->exists(['name' => 'Sk3lli']);

        self::assertLedgerRecordsBladerCreation('Sk3lli');
    }

    /**
     * A replay of `repeat.sh` runs every line again, so the second run of one
     * of these has to be a no-op rather than a unique-constraint violation.
     */
    public function testASecondRunWritesNothing(): void
    {
        PlayerFactory::createOne(['name' => 'Sk3lli']);

        $tester = $this->executeCommand(['name' => 'sk3lli']);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'The league already knows a blader called "sk3lli"');

        PlayerFactory::assert()->count(1);
        self::assertLedgerIsEmpty();
    }

    public function testItRefusesANameThatIsNotOne(): void
    {
        $tester = $this->executeCommand(['name' => '   ']);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'A blader needs a name.');

        PlayerFactory::assert()->empty();
        self::assertLedgerIsEmpty();
    }

    /**
     * The ledger doubles as the recovery script, so a blader the ledger never
     * heard of is a blader who stops existing at the next schema rebuild.
     */
    public function testLedgerFailureLeavesNobodyOnRecord(): void
    {
        self::blockLedgerWrites();

        $tester = $this->executeCommand(['name' => 'Sk3lli']);

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'the recovery ledger could not be updated');

        self::getContainer()->get('doctrine')->resetManager();

        PlayerFactory::assert()->empty();
    }
}
