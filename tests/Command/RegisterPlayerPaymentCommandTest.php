<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Factory\PlayerFactory;
use App\Factory\SeasonFactory;
use App\Factory\SeasonRegistrationFactory;
use App\Story\SeasonStory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class RegisterPlayerPaymentCommandTest extends KernelTestCase
{
    use Factories;

    public function testItRegistersAPlayerPayment(): void
    {
        $season = SeasonFactory::find([
            'slug' => 'paid-season',
        ]);

        $tester = $this->createCommandTester();

        $exitCode = $tester->execute([
            'season' => $season->getSlug(),
            'name' => 'Alice',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString(
            '"Alice" is now marked as paid',
            $tester->getDisplay(),
        );

        $alice = PlayerFactory::find([
            'name' => 'Alice',
        ]);

        SeasonRegistrationFactory::assert()->exists([
            'player' => $alice,
            'season' => $season,
            'paid' => true,
        ]);
    }

    public function testItReportsAnAlreadyPaidRegistration(): void
    {
        $season = SeasonFactory::find([
            'slug' => 'paid-season',
        ]);

        $alice = PlayerFactory::createOne([
            'name' => 'Alice',
        ]);

        SeasonRegistrationFactory::createOne([
            'player' => $alice,
            'season' => $season,
            'paid' => true,
        ]);

        $tester = $this->createCommandTester();

        $exitCode = $tester->execute([
            'season' => $season->getSlug(),
            'name' => 'Alice',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);

        self::assertStringContainsString(
            '"Alice" has already paid',
            $tester->getDisplay(),
        );

        SeasonRegistrationFactory::assert()->count(1);
    }

    public function testItFailsWhenTheSeasonDoesNotExist(): void
    {
        $tester = $this->createCommandTester();

        $exitCode = $tester->execute([
            'season' => 'missing-season',
            'name' => 'Alice',
        ]);

        self::assertSame(Command::FAILURE, $exitCode);

        self::assertStringContainsString(
            'Season "missing-season" does not exist.',
            $tester->getDisplay(),
        );

        PlayerFactory::assert()->notExists([
            'name' => 'Alice',
        ]);

        SeasonRegistrationFactory::assert()->count(0);
    }

    public function testItRejectsAnEmptyPlayerName(): void
    {
        $season = SeasonFactory::find([
            'slug' => 'paid-season',
        ]);

        $tester = $this->createCommandTester();

        $exitCode = $tester->execute([
            'season' => $season->getSlug(),
            'name' => '   ',
        ]);

        self::assertSame(Command::INVALID, $exitCode);

        self::assertStringContainsString(
            'The player name cannot be empty.',
            $tester->getDisplay(),
        );

        PlayerFactory::assert()->notExists([
            'name' => '',
        ]);

        SeasonRegistrationFactory::assert()->count(0);
    }

    private function createCommandTester(): CommandTester
    {
        self::bootKernel();

        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        return new CommandTester(
            $application->find('app:register-payment'),
        );
    }
}
