<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Factory\SeasonFactory;
use App\Tests\Support\ConsoleTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class CreateSeasonCommandTest extends ConsoleTestCase
{
    private const SLUG = 'season-x';

    private const NAME = 'Season X';

    public function testItCreatesAPaidSeason(): void
    {
        $tester = $this->createSeason(['requiresPayment' => '1']);

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertCommandSaid(
            $tester,
            'Successfully initialized season "Season X" [season-x]'
            .' (Requires Payment: YES) and updated the ledger!',
        );

        SeasonFactory::assert()->exists([
            'slug' => self::SLUG,
            'name' => self::NAME,
            'requiresPayment' => true,
        ]);
    }

    public function testItCreatesAFreeSeason(): void
    {
        $tester = $this->createSeason(['requiresPayment' => '0']);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '(Requires Payment: NO)');

        SeasonFactory::assert()->exists([
            'slug' => self::SLUG,
            'requiresPayment' => false,
        ]);
    }

    public function testItLogsAReplayableLedgerEntry(): void
    {
        $tester = $this->createSeason(['requiresPayment' => '1']);

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertLedgerRecordsSeasonCreation(
            slug: self::SLUG,
            name: self::NAME,
            requiresPayment: true,
        );
    }

    public function testItLeavesAnExistingSeasonUntouched(): void
    {
        SeasonFactory::createOne([
            'slug' => self::SLUG,
            'name' => 'The Original',
            'requiresPayment' => false,
        ]);

        $tester = $this->createSeason();

        self::assertCommandExited($tester, Command::SUCCESS);

        self::assertCommandSaid(
            $tester,
            'The season context with slug "season-x" already exists ("The Original").',
        );

        SeasonFactory::assert()->count(1);
        self::assertLedgerIsEmpty();
    }

    public function testLedgerFailureCancelsTheSeasonCreation(): void
    {
        self::blockLedgerWrites();

        $tester = $this->createSeason();

        self::assertCommandExited($tester, Command::FAILURE);

        self::assertCommandSaid(
            $tester,
            'The season was not created because the recovery ledger could not be updated.',
        );

        SeasonFactory::assert()->empty();
    }

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:create-season';
    }

    /**
     * @param array<string, string> $overrides arguments to replace
     */
    private function createSeason(array $overrides = []): CommandTester
    {
        return $this->executeCommand(array_merge([
            'slug' => self::SLUG,
            'name' => self::NAME,
            'requiresPayment' => '0',
        ], $overrides));
    }
}
