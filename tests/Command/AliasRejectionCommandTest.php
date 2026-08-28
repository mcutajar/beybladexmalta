<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Factory\PlayerAliasRejectionFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class AliasRejectionCommandTest extends ConsoleTestCase
{
    #[\Override]
    protected static function commandName(): string
    {
        return 'app:alias-rejection';
    }

    public function testItRejectsAReplayableSuggestion(): void
    {
        $steve = PlayerFactory::createOne(['name' => 'Steve']);

        $tester = $this->reject('Steve', 'Steve V.');

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, 'Steve will no longer be suggested for "Steve V.".');
        PlayerAliasRejectionFactory::assert()->exists(['player' => $steve, 'normalised' => 'stevev']);
        self::assertLedgerRecordsAliasRejection('Steve', 'Steve V.');
    }

    public function testReplayingARejectionChangesNothing(): void
    {
        PlayerFactory::createOne(['name' => 'Steve']);
        $this->reject('Steve', 'Steve V.');

        $tester = $this->reject('Steve', 'Steve V.');

        self::assertCommandExited($tester, Command::SUCCESS);
        PlayerAliasRejectionFactory::assert()->count(1);
        self::assertLedgerRecordsAliasRejection('Steve', 'Steve V.');
    }

    public function testItAllowsARejectedSuggestionAgain(): void
    {
        $steve = PlayerFactory::createOne(['name' => 'Steve']);
        PlayerAliasRejectionFactory::createOne(['player' => $steve, 'spelling' => 'Steve V.']);

        $tester = $this->executeCommand(['action' => 'allow', 'names' => ['Steve', 'Steve V.']]);

        self::assertCommandExited($tester, Command::SUCCESS);
        PlayerAliasRejectionFactory::assert()->empty();
        self::assertLedgerRecordsAliasAllowance('Steve', 'Steve V.');
    }

    public function testAFailedLedgerWriteLeavesNoRejection(): void
    {
        PlayerFactory::createOne(['name' => 'Steve']);
        self::blockLedgerWrites();

        $tester = $this->reject('Steve', 'Steve V.');

        self::assertCommandExited($tester, Command::FAILURE);
        self::assertCommandSaid($tester, 'The rejection table was left alone');
        PlayerAliasRejectionFactory::assert()->empty();
    }

    public function testItNamesTheActionsItKnows(): void
    {
        $tester = $this->executeCommand(['action' => 'frobnicate']);

        self::assertCommandExited($tester, Command::INVALID);
        self::assertCommandSaid($tester, 'The actions are: reject, allow.');
    }

    private function reject(string $blader, string $spelling): CommandTester
    {
        return $this->executeCommand(['action' => 'reject', 'names' => [$blader, $spelling]]);
    }
}
