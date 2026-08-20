<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Zenstruck\Foundry\Test\Factories;

/**
 * Base for tests that drive one console command.
 */
abstract class ConsoleTestCase extends KernelTestCase
{
    use Factories;
    use InteractsWithTheLedger;
    use LeagueAssertions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->discardArtifacts();
    }

    protected function tearDown(): void
    {
        $this->discardArtifacts();

        parent::tearDown();
    }

    /**
     * The command under test, e.g. `app:register-payment`.
     */
    abstract protected static function commandName(): string;

    /**
     * Files a test may leave behind. `var/data/imports/` is tracked by git, so
     * anything written there has to be cleaned up.
     *
     * @return list<string>
     */
    protected function artifactPaths(): array
    {
        return [self::ledgerPath()];
    }

    /**
     * @param array<string, string> $input   arguments and options
     * @param list<string>          $answers replies to interactive questions
     */
    protected function executeCommand(array $input, array $answers = []): CommandTester
    {
        $application = new Application(self::bootKernel());
        $application->setAutoExit(false);

        $tester = new CommandTester(
            $application->find(static::commandName()),
        );

        if ([] !== $answers) {
            $tester->setInputs($answers);
        }

        $tester->execute($input);

        return $tester;
    }

    protected static function assertCommandExited(
        CommandTester $tester,
        int $expected,
    ): void {
        self::assertSame($expected, $tester->getStatusCode());
    }

    /**
     * SymfonyStyle hard-wraps its blocks, so collapse the whitespace before
     * matching against a message.
     */
    protected static function assertCommandSaid(
        CommandTester $tester,
        string $message,
    ): void {
        self::assertStringContainsString(
            $message,
            (string) preg_replace('/\s+/', ' ', $tester->getDisplay()),
        );
    }

    private function discardArtifacts(): void
    {
        foreach ($this->artifactPaths() as $path) {
            self::removePath($path);
        }
    }
}
