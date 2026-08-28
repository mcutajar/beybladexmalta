<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Support\AdminPageTestCase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Zenstruck\Foundry\Attribute\ResetDatabase;

#[ResetDatabase]
final class MergePlayerControllerTest extends AdminPageTestCase
{
    private const PAGE = '/admin/merge-player';

    public function testWrongPassphraseWritesNothing(): void
    {
        [$from, $into] = $this->players();
        $client = $this->createBrowser();

        $this->submitMerge($client, $from->getId(), $into->getId(), 'wrong', true);

        self::assertResponseRedirects(self::PAGE);
        PlayerFactory::assert()->count(2);
        self::assertLedgerIsEmpty();
        $this->assertFlashSays($client, 'Authentication failed.');
    }

    public function testEmptyPassphraseWritesNothing(): void
    {
        [$from, $into] = $this->players();
        $client = $this->createBrowser();

        $this->submitMerge($client, $from->getId(), $into->getId(), '', true);

        self::assertResponseRedirects(self::PAGE);
        PlayerFactory::assert()->count(2);
        self::assertLedgerIsEmpty();
    }

    public function testUntickedConfirmationIsADryRun(): void
    {
        [$from, $into] = $this->players();
        $client = $this->createBrowser();

        $this->submitMerge($client, $from->getId(), $into->getId(), self::ADMIN_PASSPHRASE, false);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'What will move');
        PlayerFactory::assert()->count(2);
        self::assertLedgerIsEmpty();
    }

    public function testConfirmedMergeRedirectsTheOldProfilePermanently(): void
    {
        [$from, $into] = $this->players();
        $season = SeasonFactory::createOne(['slug' => 'one']);
        $oldId = $from->getId();
        $client = $this->createBrowser();

        $this->submitMerge($client, $oldId, $into->getId(), self::ADMIN_PASSPHRASE, true);
        self::assertResponseRedirects(self::PAGE);

        $client->request('GET', sprintf('/season/one/player/%d', $oldId));
        self::assertResponseRedirects(sprintf('/season/one/player/%d', $into->getId()), 301);
        self::assertLedgerRecordsPlayerMerge('Old Name', 'Survivor');
    }

    private function submitMerge(KernelBrowser $client, ?int $from, ?int $into, string $passphrase, bool $confirm): void
    {
        $fields = [
            'merge_player[from]' => (string) $from,
            'merge_player[into]' => (string) $into,
            'merge_player[passphrase]' => $passphrase,
        ];
        if ($confirm) {
            $fields['merge_player[confirm]'] = '1';
        }
        $this->submitFormAt($client, self::PAGE, $fields);
    }

    /** @return array{\App\Entity\Player, \App\Entity\Player} */
    private function players(): array
    {
        return [PlayerFactory::createOne(['name' => 'Old Name']), PlayerFactory::createOne(['name' => 'Survivor'])];
    }
}
