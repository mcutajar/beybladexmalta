<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Factory\PlayerFactory;
use App\Factory\SeasonRegistrationFactory;
use App\Story\PaidRegistrationStory;
use App\Story\SeasonStory;
use App\Story\UnpaidRegistrationStory;
use App\Tests\Support\AdminPageTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class RegisterPaymentControllerTest extends AdminPageTestCase
{
    private const PAGE = '/admin/payments';

    public function testPageDisplaysAllStorySeasons(): void
    {
        $client = $this->createBrowser();

        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertRouteSame('admin_register_payment');

        self::assertSeasonIsSelectable('register_payment', 'paid-season', 'Paid Season');
        self::assertSeasonIsSelectable('register_payment', 'free-season', 'Free Season');
    }

    public function testIncorrectPassphraseDoesNotCreatePlayerOrRegistration(): void
    {
        $client = $this->createBrowser();

        $this->submitPayment($client, playerName: 'Charlie', passphrase: 'wrong-passphrase');

        self::assertResponseRedirects(self::PAGE);
        self::assertNothingWasRegistered();
        self::assertLedgerIsEmpty();

        $this->assertFlashSays($client, 'Authentication failed.');
    }

    public function testSuccessfulPaymentCreatesNewPlayerAndRegistration(): void
    {
        $client = $this->createBrowser();

        $this->submitPayment($client, playerName: '  Charlie  ');

        self::assertResponseRedirects(self::PAGE);

        self::assertPlayerHasPaid('Charlie');
        SeasonRegistrationFactory::assert()->count(1);

        self::assertLedgerRecordsPayment('paid-season', 'Charlie');

        $this->assertFlashSays($client, 'Successfully processed transaction.');
    }

    public function testExistingPlayerIsFoundCaseInsensitively(): void
    {
        UnpaidRegistrationStory::load();

        self::assertPlayerHasNotPaid('Alice');

        $client = $this->createBrowser();

        $this->submitPayment($client, playerName: '  aLiCe  ');

        self::assertResponseRedirects(self::PAGE);

        /*
         * The existing player should be reused rather than creating a
         * duplicate with different capitalization.
         */
        PlayerFactory::assert()->count(1);
        SeasonRegistrationFactory::assert()->count(1);
        self::assertPlayerHasPaid('Alice');

        self::assertLedgerRecordsPayment('paid-season', 'Alice');

        $this->assertFlashSays($client, 'Successfully processed transaction.');
    }

    public function testAlreadyPaidRegistrationIsNotProcessedAgain(): void
    {
        PaidRegistrationStory::load();

        $client = $this->createBrowser();

        $this->submitPayment($client, playerName: 'Bob');

        self::assertResponseRedirects(self::PAGE);

        PlayerFactory::assert()->count(1);
        SeasonRegistrationFactory::assert()->count(1);
        self::assertPlayerHasPaid('Bob');

        /*
         * An already-paid registration should not produce another ledger
         * entry.
         */
        self::assertLedgerIsEmpty();

        $this->assertFlashSays($client, 'Blader has already cleared their balance.');
    }

    public function testLedgerFailureDisplaysCriticalError(): void
    {
        self::blockLedgerWrites();

        $client = $this->createBrowser();

        $this->submitPayment($client, playerName: 'Ledger Failure Player');

        self::assertResponseRedirects(self::PAGE);
        self::assertNothingWasRegistered();

        $this->assertFlashSays(
            $client,
            'Critical failure: Failed to write to ledger file, update cancelled.',
        );
    }

    public function testFailedFlushLeavesNoLedgerEntry(): void
    {
        $client = $this->createBrowser();

        /*
         * players.name is a VARCHAR(255), so this name is rejected when the
         * flush runs rather than when the entity is built.
         */
        $this->submitPayment($client, playerName: str_repeat('a', 300));

        self::assertResponseRedirects(self::PAGE);

        $this->resetEntityManager();

        self::assertNothingWasRegistered();

        /*
         * Replaying an orphan ledger line would mark a payment that was never
         * recorded, so a failed write must not leave one behind.
         */
        self::assertLedgerIsEmpty();

        $this->assertFlashSays(
            $client,
            'A critical failure occurred while processing the transaction.',
        );
    }

    private function submitPayment(
        KernelBrowser $client,
        string $playerName,
        string $seasonSlug = 'paid-season',
        string $passphrase = self::ADMIN_PASSPHRASE,
    ): void {
        $this->submitFormAt($client, self::PAGE, [
            'register_payment[season]' => $seasonSlug,
            'register_payment[playerName]' => $playerName,
            'register_payment[passphrase]' => $passphrase,
        ]);
    }
}
