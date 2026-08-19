<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\PlayerFactory;
use App\Factory\SeasonRegistrationFactory;
use App\Story\PaidRegistrationStory;
use App\Story\SeasonStory;
use App\Story\UnpaidRegistrationStory;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class RegisterPaymentControllerTest extends WebTestCase
{
    private const ADMIN_PASSPHRASE = 'test-passphrase';

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledgerPath = dirname(__DIR__, 2)
            .'/var/log/command_ledger.sh';

        $this->removeLedgerArtifact();
    }

    protected function tearDown(): void
    {
        $this->removeLedgerArtifact();

        parent::tearDown();
    }

    public function testPageDisplaysAllStorySeasons(): void
    {
        $client = $this->createBrowser();

        $client->request('GET', '/admin/payments');

        self::assertResponseIsSuccessful();
        self::assertRouteSame('admin_register_payment');

        self::assertSelectorTextContains(
            'select[name="register_payment[season]"]',
            'Paid Season'
        );

        self::assertSelectorTextContains(
            'select[name="register_payment[season]"]',
            'Free Season'
        );

        self::assertSelectorExists(
            'select[name="register_payment[season]"] '
            .'option[value="paid-season"]'
        );

        self::assertSelectorExists(
            'select[name="register_payment[season]"] '
            .'option[value="free-season"]'
        );
    }

    public function testIncorrectPassphraseDoesNotCreatePlayerOrRegistration(): void
    {
        $client = $this->createBrowser();

        $this->submitPayment(
            client: $client,
            seasonSlug: SeasonStory::paymentSeason()->getSlug(),
            playerName: 'Charlie',
            passphrase: 'wrong-passphrase',
        );

        self::assertResponseRedirects('/admin/payments');

        PlayerFactory::assert()->notExists([
            'name' => 'Charlie',
        ]);

        SeasonRegistrationFactory::assert()->empty();

        self::assertFileDoesNotExist($this->ledgerPath);

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'Authentication failed.'
        );
    }

    public function testSuccessfulPaymentCreatesNewPlayerAndRegistration(): void
    {
        $client = $this->createBrowser();

        $this->submitPayment(
            client: $client,
            seasonSlug: SeasonStory::paymentSeason()->getSlug(),
            playerName: '  Charlie  ',
            passphrase: self::ADMIN_PASSPHRASE,
        );

        self::assertResponseRedirects('/admin/payments');

        PlayerFactory::assert()->exists([
            'name' => 'Charlie',
        ]);

        $charlie = PlayerFactory::find([
            'name' => 'Charlie',
        ]);

        SeasonRegistrationFactory::assert()->exists([
            'player' => $charlie,
            'season' => SeasonStory::paymentSeason(),
            'paid' => true,
        ]);

        SeasonRegistrationFactory::assert()->count(1);

        self::assertFileExists($this->ledgerPath);

        $expectedCommand = sprintf(
            "php bin/console app:register-payment %s %s\n",
            escapeshellarg('paid-season'),
            escapeshellarg('Charlie'),
        );

        self::assertSame(
            $expectedCommand,
            file_get_contents($this->ledgerPath)
        );

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'Successfully processed transaction.'
        );
    }

    public function testExistingPlayerIsFoundCaseInsensitively(): void
    {
        UnpaidRegistrationStory::load();

        $alice = PlayerFactory::find([
            'name' => 'Alice',
        ]);

        SeasonRegistrationFactory::assert()->exists([
            'player' => $alice,
            'season' => SeasonStory::paymentSeason(),
            'paid' => false,
        ]);

        $client = $this->createBrowser();

        $this->submitPayment(
            client: $client,
            seasonSlug: SeasonStory::paymentSeason()->getSlug(),
            playerName: '  aLiCe  ',
            passphrase: self::ADMIN_PASSPHRASE,
        );

        self::assertResponseRedirects('/admin/payments');

        /*
         * The existing player should be reused rather than creating a
         * duplicate with different capitalization.
         */
        PlayerFactory::assert()->count(1);

        PlayerFactory::assert()->exists([
            'name' => 'Alice',
        ]);

        SeasonRegistrationFactory::assert()->count(1);

        SeasonRegistrationFactory::assert()->exists([
            'player' => $alice,
            'season' => SeasonStory::paymentSeason(),
            'paid' => true,
        ]);

        self::assertFileExists($this->ledgerPath);

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'Successfully processed transaction.'
        );
    }

    public function testAlreadyPaidRegistrationIsNotProcessedAgain(): void
    {
        PaidRegistrationStory::load();

        $bob = PlayerFactory::find([
            'name' => 'Bob',
        ]);

        SeasonRegistrationFactory::assert()->exists([
            'player' => $bob,
            'season' => SeasonStory::paymentSeason(),
            'paid' => true,
        ]);

        $client = $this->createBrowser();

        $this->submitPayment(
            client: $client,
            seasonSlug: SeasonStory::paymentSeason()->getSlug(),
            playerName: 'Bob',
            passphrase: self::ADMIN_PASSPHRASE,
        );

        self::assertResponseRedirects('/admin/payments');

        PlayerFactory::assert()->count(1);
        SeasonRegistrationFactory::assert()->count(1);

        SeasonRegistrationFactory::assert()->exists([
            'player' => $bob,
            'season' => SeasonStory::paymentSeason(),
            'paid' => true,
        ]);

        /*
         * An already-paid registration should not produce another
         * ledger entry.
         */
        self::assertFileDoesNotExist($this->ledgerPath);

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'Blader has already cleared their balance.'
        );
    }

    public function testLedgerFailureDisplaysCriticalError(): void
    {
        /*
         * file_put_contents() cannot write to a directory as though it
         * were a regular file. This forces the ledger write to fail.
         */
        self::assertTrue(mkdir($this->ledgerPath));

        $client = $this->createBrowser();

        $this->submitPayment(
            client: $client,
            seasonSlug: SeasonStory::paymentSeason()->getSlug(),
            playerName: 'Ledger Failure Player',
            passphrase: self::ADMIN_PASSPHRASE,
        );

        self::assertResponseRedirects('/admin/payments');

        PlayerFactory::assert()->empty([
            'name' => 'Ledger Failure Player',
        ]);

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'Critical failure: Failed to write to ledger file, update cancelled.'
        );
    }

    public function testFailedFlushLeavesNoLedgerEntry(): void
    {
        /*
         * players.name is a VARCHAR(255), so this name is rejected when the
         * flush runs rather than when the entity is built.
         */
        $client = $this->createBrowser();

        $this->submitPayment(
            client: $client,
            seasonSlug: SeasonStory::paymentSeason()->getSlug(),
            playerName: str_repeat('a', 300),
            passphrase: self::ADMIN_PASSPHRASE,
        );

        self::assertResponseRedirects('/admin/payments');

        $this->resetEntityManager();

        PlayerFactory::assert()->empty();
        SeasonRegistrationFactory::assert()->empty();

        /*
         * Replaying an orphan ledger line would mark a payment that was
         * never recorded, so a failed write must not leave one behind.
         */
        self::assertFileDoesNotExist($this->ledgerPath);

        $client->followRedirect();

        self::assertSelectorTextContains(
            'body',
            'A critical failure occurred while processing the transaction.'
        );
    }

    private function resetEntityManager(): void
    {
        /*
         * Doctrine closes the entity manager when a flush fails, so reset it
         * before asserting through the factories.
         */
        self::getContainer()->get('doctrine')->resetManager();
    }

    private function createBrowser(): KernelBrowser
    {
        /*
         * Foundry stories and factories may boot the kernel before this
         * point. WebTestCase needs to boot its browser kernel itself.
         */
        static::ensureKernelShutdown();

        return static::createClient();
    }

    private function submitPayment(
        KernelBrowser $client,
        string $seasonSlug,
        string $playerName,
        string $passphrase,
    ): void {
        /*
         * Requesting the page first provides the real form and its CSRF
         * token.
         */
        $crawler = $client->request(
            'GET',
            '/admin/payments'
        );

        self::assertResponseIsSuccessful();

        $form = $crawler
            ->filter('form')
            ->first()
            ->form([
                'register_payment[season]' => $seasonSlug,
                'register_payment[playerName]' => $playerName,
                'register_payment[passphrase]' => $passphrase,
            ]);

        $client->submit($form);
    }

    private function removeLedgerArtifact(): void
    {
        if (!isset($this->ledgerPath)) {
            return;
        }

        if (is_file($this->ledgerPath)) {
            unlink($this->ledgerPath);

            return;
        }

        if (is_dir($this->ledgerPath)) {
            rmdir($this->ledgerPath);
        }
    }
}
