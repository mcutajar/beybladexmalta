<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

/**
 * Base for tests that drive one service against the real database.
 *
 * The kernel and the artifact discipline, and nothing else. ConsoleTestCase
 * adds the command plumbing on top; a service worth testing without a command
 * in front of it — a resolver, a parser that reads a table — extends this
 * directly rather than KernelTestCase.
 */
abstract class ServiceTestCase extends KernelTestCase
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
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    protected function service(string $id): object
    {
        $service = self::getContainer()->get($id);

        self::assertInstanceOf($id, $service);

        return $service;
    }

    private function discardArtifacts(): void
    {
        foreach ($this->artifactPaths() as $path) {
            self::removePath($path);
        }
    }
}
