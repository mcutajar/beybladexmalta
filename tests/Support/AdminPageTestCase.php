<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Base for tests that drive an admin page through the browser.
 *
 * Admin routes are gated by a passphrase submitted in the form rather than by
 * a security firewall, so the correct passphrase is the default everywhere and
 * only the tests that probe authentication mention one.
 */
abstract class AdminPageTestCase extends PageTestCase
{
    use LeagueAssertions;

    protected const ADMIN_PASSPHRASE = 'test-passphrase';

    /**
     * Requesting the page first provides the real form and its CSRF token.
     *
     * The form is found by the name its fields are under rather than by being
     * the first one on the page, because `/admin/import` now carries two: a
     * bracket URL and the textarea. Picking by position would mean the order
     * of two cards decided which test passed.
     *
     * @param array<string, string> $fields
     */
    protected function submitFormAt(
        KernelBrowser $client,
        string $path,
        array $fields,
    ): void {
        $crawler = $client->request('GET', $path);

        self::assertResponseIsSuccessful();

        $client->submit($this->formCarrying($crawler, $fields)->form($fields));
    }

    /**
     * The form on the page whose fields these are.
     *
     * @param array<string, string> $fields
     */
    private function formCarrying(Crawler $crawler, array $fields): Crawler
    {
        $name = (string) strtok((string) array_key_first($fields), '[');
        $named = $crawler->filter(sprintf('form[name="%s"]', $name));

        return $named->count() > 0 ? $named->first() : $crawler->filter('form')->first();
    }

    /**
     * Every admin outcome is reported as a flash on the page that follows the
     * redirect.
     */
    protected function assertFlashSays(
        KernelBrowser $client,
        string $message,
    ): void {
        $client->followRedirect();

        self::assertSelectorTextContains('body', $message);
    }

    protected static function assertSeasonIsSelectable(
        string $formName,
        string $slug,
        ?string $label = null,
    ): void {
        $select = sprintf('select[name="%s[season]"]', $formName);

        self::assertSelectorExists(
            sprintf('%s option[value="%s"]', $select, $slug),
        );

        if (null !== $label) {
            self::assertSelectorTextContains($select, $label);
        }
    }

    /**
     * Doctrine closes the entity manager when a flush fails, so reset it
     * before asserting through the factories.
     */
    protected function resetEntityManager(): void
    {
        self::getContainer()->get('doctrine')->resetManager();
    }
}
