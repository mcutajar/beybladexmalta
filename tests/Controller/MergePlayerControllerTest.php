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

    /**
     * Both of the losing blader's public URLs survive the merge: the id form
     * every season-scoped route used, and the slug form #95 made canonical.
     * The row is deleted, so the redirect table is the only thing that
     * remembers either — and the season, which was never part of a blader's
     * identity, comes across as the query parameter it now is.
     */
    public function testConfirmedMergeRedirectsTheOldProfilePermanently(): void
    {
        [$from, $into] = $this->players();
        SeasonFactory::createOne(['slug' => 'one']);
        $oldId = $from->getId();
        $oldSlug = $from->getSlug();
        $client = $this->createBrowser();

        $this->submitMerge($client, $oldId, $into->getId(), self::ADMIN_PASSPHRASE, true);
        self::assertResponseRedirects(self::PAGE);

        $client->request('GET', sprintf('/season/one/player/%d', $oldId));
        self::assertResponseRedirects(sprintf('/player/%s?season=one', $into->getSlug()), 301);

        $client->request('GET', sprintf('/player/%s?season=one', $oldSlug));
        self::assertResponseRedirects(sprintf('/player/%s?season=one', $into->getSlug()), 301);

        $client->request('GET', sprintf('/player/%s', $oldSlug));
        self::assertResponseRedirects(sprintf('/player/%s', $into->getSlug()), 301);

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
