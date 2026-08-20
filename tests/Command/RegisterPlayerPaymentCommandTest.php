<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Factory\SeasonRegistrationFactory;
use App\Story\PaidRegistrationStory;
use App\Story\SeasonStory;
use App\Tests\Support\ConsoleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Command\Command;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class RegisterPlayerPaymentCommandTest extends ConsoleTestCase
{
    private const SEASON = 'paid-season';

    public function testItRegistersAPlayerPayment(): void
    {
        $tester = $this->executeCommand([
            'season' => self::SEASON,
            'name' => 'Alice',
        ]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '"Alice" is now marked as paid');
        self::assertPlayerHasPaid('Alice');
    }

    public function testItReportsAnAlreadyPaidRegistration(): void
    {
        PaidRegistrationStory::load();

        $tester = $this->executeCommand([
            'season' => self::SEASON,
            'name' => 'Bob',
        ]);

        self::assertCommandExited($tester, Command::SUCCESS);
        self::assertCommandSaid($tester, '"Bob" has already paid');

        SeasonRegistrationFactory::assert()->count(1);
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('rejectedInput')]
    public function testItRejectsUnusableInput(
        array $input,
        int $expectedExit,
        string $expectedMessage,
    ): void {
        $tester = $this->executeCommand($input);

        self::assertCommandExited($tester, $expectedExit);
        self::assertCommandSaid($tester, $expectedMessage);
        self::assertNothingWasRegistered();
    }

    /**
     * @return iterable<string, array{array<string, string>, int, string}>
     */
    public static function rejectedInput(): iterable
    {
        yield 'unknown season' => [
            ['season' => 'missing-season', 'name' => 'Alice'],
            Command::FAILURE,
            'Season "missing-season" does not exist.',
        ];

        yield 'blank player name' => [
            ['season' => self::SEASON, 'name' => '   '],
            Command::INVALID,
            'The player name cannot be empty.',
        ];
    }

    #[\Override]
    protected static function commandName(): string
    {
        return 'app:register-payment';
    }
}
