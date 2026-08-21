<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Base for tests that drive a page through the browser.
 *
 * Holds the browser and the artifact discipline; AdminPageTestCase adds the
 * form and passphrase helpers on top.
 */
abstract class PageTestCase extends WebTestCase
{
    use InteractsWithTheLedger;

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
     * Foundry stories and factories may boot the kernel before this point.
     * WebTestCase needs to boot its browser kernel itself.
     */
    protected function createBrowser(): KernelBrowser
    {
        static::ensureKernelShutdown();

        return static::createClient();
    }

    /**
     * A page that renders is the bar here: these are smoke tests over the
     * templates, not assertions about what the page says.
     */
    protected function assertPageRenders(string $path): void
    {
        $this->createBrowser()->request('GET', $path);

        self::assertResponseIsSuccessful(sprintf('Expected %s to render.', $path));
    }

    private function discardArtifacts(): void
    {
        foreach ($this->artifactPaths() as $path) {
            self::removePath($path);
        }
    }
}
