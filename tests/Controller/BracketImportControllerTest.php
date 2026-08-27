<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\AdminPageTestCase;
use App\Tests\Support\FakeChallonge;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

/**
 * The second way into `/admin/import`, driven the way somebody uses it.
 *
 * The fixture bracket is four entrants over a Swiss group and a cut, and the
 * league is set up so that two of the four names read straight through — one
 * by its own spelling, one through an alias — and two do not. That is the
 * shape of every real bracket in miniature, and it is the only shape worth
 * testing: a preview with nothing to answer proves nothing about the rule the
 * whole epic turns on.
 */
#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class BracketImportControllerTest extends AdminPageTestCase
{
    private const PAGE = '/admin/import';

    private const TITLE = 'Bracket Test Cup';

    private const DATE = '2026-08-16';

    private const SEASON = 'paid-season';

    /**
     * The two entrants the league can already read: one under its own name,
     * one through an alias somebody filed earlier.
     */
    private const KNOWN = 'Obelix';

    private const ALIASED = 'Sanya';

    private const ALIAS = 'Sanya0207';

    /**
     * The two it cannot. Both finish below the podium in the fixture, which is
     * where the unreadable names really are: fifty-two spellings across the
     * captured brackets reach nobody and every one of them finished eleventh
     * or worse.
     */
    private const UNKNOWN = 'Guy "The {Bracket}" \o/';

    private const UNKNOWN_KEY = 'guythebracketo';

    private const MISSPELLED = 'giglio15 (invitation pending)';

    private const MISSPELLED_KEY = 'giglio15';

    public function testTheEntryFormOffersEverySeason(): void
    {
        $client = $this->createBrowser();

        $client->request('GET', self::PAGE);

        self::assertResponseIsSuccessful();
        self::assertSeasonIsSelectable('bracket_import', 'paid-season');
        self::assertSeasonIsSelectable('bracket_import', 'free-season');
    }

    public function testTheTextareaFormIsStillThere(): void
    {
        $client = $this->createBrowser();

        $client->request('GET', self::PAGE);

        self::assertSelectorExists('form[name="bracket_import"]');
        self::assertSelectorExists('form[name="import_tournament"]');
    }

    public function testFetchingABracketWritesNothing(): void
    {
        $this->league();

        $crawler = $this->fetchBracket($this->createBrowser());

        self::assertResponseIsSuccessful();

        // The counts that prove the right bracket came back.
        self::assertSelectorTextContains('body', '7');
        self::assertSelectorTextContains('body', '90');

        // Both readable names resolved; both unreadable ones are questions.
        self::assertCount(1, $crawler->filter('fieldset'), 'One live question.');
        self::assertCount(1, $crawler->filter('select[name^="decision["]'), 'One settled.');
        self::assertSelectorTextContains('body', self::UNKNOWN);
        self::assertSelectorTextContains('body', self::MISSPELLED);

        // And the ledger lines are shown before, not after.
        self::assertSelectorTextContains('body', 'app:import-tournament');
        self::assertSelectorTextContains('body', 'app:archive-challonge');

        self::assertNoEventWasImported();
        self::assertNobodyWasInvented();
        self::assertLedgerIsEmpty();
        self::assertFileDoesNotExist($this->snapshotPath());
        self::assertFileDoesNotExist($this->importPath());
    }

    /**
     * The safe way round. An unnecessary blader is a duplicate row you can see
     * and merge; an unnecessary alias welds two people together and cannot be
     * undone. So the screen answers the first kind and never the second.
     */
    public function testANameWithNothingCloseArrivesAnsweredAsSomebodyNew(): void
    {
        $this->league();

        $this->fetchBracket($this->createBrowser());

        /*
         * A picker rather than buttons, because the only thing left to do with
         * it is disagree — and disagreeing means finding one blader in a list
         * of all of them.
         */
        self::assertSelectorExists(sprintf(
            'select[name="decision[%s]"] option[value="create"][selected]',
            self::UNKNOWN_KEY,
        ));

        // And it is folded away, because it is not asking anything.
        self::assertSelectorTextContains('body', '1 with nothing close');
    }

    public function testANameWithASuggestionIsNeverAnsweredForYou(): void
    {
        $this->league();

        $crawler = $this->fetchBracket($this->createBrowser());

        $group = $crawler->filter(sprintf('input[name="decision[%s]"]', self::MISSPELLED_KEY));

        self::assertGreaterThan(1, $group->count(), 'The suggestion, new blader and not-a-person.');
        self::assertCount(0, $group->filter('[checked]'), 'Nothing is pre-selected.');

        // The browser refuses to submit until it is answered.
        self::assertSelectorExists(sprintf('input[name="decision[%s]"][required]', self::MISSPELLED_KEY));

        // The suggestion is offered first, and says why.
        self::assertSelectorTextContains('body', 'Giglio');
        self::assertSelectorTextContains('body', 'edits from');
    }

    /**
     * The point of seeding: only the names with a suggestion cost anything.
     */
    public function testOnlyTheSuggestedNameHasToBeAnswered(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        $this->confirm($client, $crawler, [
            'decision['.self::MISSPELLED_KEY.']' => 'blader:'.PlayerFactory::find(['name' => 'Giglio'])->getId(),
        ]);

        self::assertResponseRedirects(self::PAGE);

        // The seeded row still created its blader, and the flash says so.
        PlayerFactory::assert()->exists(['name' => self::UNKNOWN]);

        $this->assertFlashSays($client, '1 blader created (1 by default)');
    }

    public function testAnUnansweredNameStopsTheImport(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        /*
         * Submitted exactly as rendered. The seeded row carries its default,
         * the row with a suggestion carries nothing, and that is the state a
         * confirm has to refuse.
         */
        $this->confirm($client, $crawler, []);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Nothing was written');

        self::assertNoEventWasImported();
        self::assertNobodyWasInvented();
        self::assertLedgerIsEmpty();
        self::assertFileDoesNotExist($this->snapshotPath());
    }

    public function testAnIncorrectPassphraseWritesNothing(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        $this->confirm($client, $crawler, $this->everyNameAnswered(), passphrase: 'wrong-passphrase');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Authentication failed.');

        self::assertNoEventWasImported();
        self::assertNobodyWasInvented();
        self::assertLedgerIsEmpty();
    }

    public function testConfirmingScoresTheBracketAndArchivesAllOfIt(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        $this->confirm($client, $crawler, $this->everyNameAnswered());

        self::assertResponseRedirects(self::PAGE);

        $tournament = self::findTournament(self::TITLE);

        /*
         * The bracket's own standings order, with the aliased and the newly
         * created names resolved to the bladers the league holds.
         */
        self::assertPlacementsScoredInOrder($tournament, [
            self::KNOWN,
            self::ALIASED,
            self::UNKNOWN,
            'Giglio',
        ]);

        // Obelix won the cut, so first place carries the bonus on top of it.
        self::assertResultAtRank($tournament, rank: 1, bonusPoints: 10, totalPoints: 35);

        // The archive is additive: every entrant row, not just the scoring four.
        self::assertCount(2, $tournament->getStages());

        $this->assertFlashSays($client, 'Imported "Bracket Test Cup" from fixture1');
    }

    public function testConfirmingWritesEveryReplayableLine(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        $this->confirm($client, $crawler, $this->everyNameAnswered());

        self::assertResponseRedirects(self::PAGE);

        self::assertFileExists($this->snapshotPath());
        self::assertFileExists($this->importPath());

        /*
         * The order is the order it replays in: the blader before the alias
         * that spells them and the import that scores them, and the archive
         * after the import it is written against.
         */
        self::assertSame(
            [
                sprintf('php bin/console app:create-blader %s', escapeshellarg(self::UNKNOWN)),
                sprintf('php bin/console app:alias add %s %s', escapeshellarg('Giglio'), escapeshellarg(self::MISSPELLED)),
                sprintf(
                    'php bin/console app:import-tournament %s %s %s --season=%s --challonge=%s --knockout=%s',
                    escapeshellarg(self::TITLE),
                    escapeshellarg(self::DATE),
                    escapeshellarg($this->importPath()),
                    escapeshellarg(self::SEASON),
                    escapeshellarg('challonge.com/'.FakeChallonge::SLUG),
                    escapeshellarg(self::KNOWN),
                ),
                sprintf('php bin/console app:archive-challonge %s', escapeshellarg(FakeChallonge::SLUG)),
            ],
            $this->ledgerLines(),
        );

        // The placement file holds the league's names, never the bracket's.
        self::assertSame(
            implode("\n", [self::KNOWN, self::ALIASED, self::UNKNOWN, 'Giglio'])."\n",
            file_get_contents($this->importPath()),
        );
    }

    public function testTheOrderCanBeCorrected(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        /*
         * The bracket has Obelix first and the unknown entrant third. Swapping
         * the two moves the F1 tiers with them — and the knockout bonus does
         * not move, because it belongs to whoever won the cut.
         */
        $this->confirm($client, $crawler, [
            ...$this->everyNameAnswered(),
            'order[0]' => '3',
            'order[2]' => '1',
        ]);

        self::assertResponseRedirects(self::PAGE);

        $tournament = self::findTournament(self::TITLE);

        self::assertResultAtRank($tournament, rank: 1, player: self::UNKNOWN, f1Points: 25, bonusPoints: 0);
        self::assertResultAtRank($tournament, rank: 3, player: self::KNOWN, f1Points: 15, bonusPoints: 10);
    }

    public function testAnEntrantWhoIsNotAPersonIsDropped(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        $this->confirm($client, $crawler, [
            'decision['.self::UNKNOWN_KEY.']' => 'drop',
            'decision['.self::MISSPELLED_KEY.']' => 'create',
        ]);

        self::assertResponseRedirects(self::PAGE);

        $tournament = self::findTournament(self::TITLE);

        TournamentResultFactory::assert()->count(3);

        /*
         * Nothing renumbers around a dropped Challonge rank, but the league's
         * own placement is the row's place in the list — so the entrant below
         * the dropped one moves up and is paid for third.
         */
        self::assertResultAtRank($tournament, rank: 3, player: self::MISSPELLED, f1Points: 15);
    }

    /**
     * The one question this screen cannot ask. A spelling more than one blader
     * already answers to gets no select at all, because no alias can settle
     * it: two rows for one person is the merge in #56, and a blader whose name
     * shadows an alias is the alias to remove.
     */
    public function testASpellingTwoBladersAnswerToBlocksTheImport(): void
    {
        $this->league();

        PlayerAliasFactory::createOne([
            'player' => PlayerFactory::createOne(['name' => 'Somebody else']),
            'alias' => self::KNOWN,
        ]);

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        self::assertSelectorTextContains('body', 'is how more than one blader is already spelled');
        self::assertCount(0, $crawler->filter('select[name="decision[obelix]"]'));

        $this->confirm($client, $crawler, $this->everyNameAnswered());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Nothing was written');
        self::assertNoEventWasImported();
        self::assertLedgerIsEmpty();
    }

    public function testABracketAnEventAlreadyNamesIsRefused(): void
    {
        $this->league();

        TournamentFactory::createOne([
            'title' => 'The first time round',
            'season' => SeasonStory::paymentSeason(),
            'challongeUrl' => 'https://challonge.com/'.FakeChallonge::SLUG,
        ]);

        $client = $this->createBrowser();

        $this->fetchBracket($client);

        self::assertResponseRedirects(self::PAGE);

        $this->assertFlashSays($client, 'was already imported from "fixture1"');
    }

    public function testABracketThatCannotBeReadIsRefusedBeforeAnythingElse(): void
    {
        $this->league();

        $client = $this->createBrowser();

        $this->fetchBracket($client, url: 'challonge.com/'.FakeChallonge::BOUNCED_SLUG);

        self::assertResponseRedirects(self::PAGE);

        $this->assertFlashSays($client, 'The bracket could not be read');
        self::assertFileDoesNotExist($this->snapshotPath());
    }

    public function testALooseDateIsRefusedBeforeTheNetworkCall(): void
    {
        $this->league();

        $client = $this->createBrowser();

        $this->fetchBracket($client, date: '16/08/2026');

        self::assertResponseRedirects(self::PAGE);

        $this->assertFlashSays($client, 'Please use the strict format structure YYYY-MM-DD');
    }

    public function testCancellingLeavesNothingToConfirm(): void
    {
        $this->league();

        $client = $this->createBrowser();
        $crawler = $this->fetchBracket($client);

        $client->submit($crawler->filter('button[name="cancel"]')->form());

        self::assertResponseRedirects(self::PAGE);
        self::assertNoEventWasImported();

        // And the draft is gone, so a replayed confirm has nothing to apply.
        $client->request('POST', '/admin/import/bracket/confirm', [
            'bracket_confirm' => ['slug' => FakeChallonge::SLUG],
        ]);

        self::assertResponseRedirects(self::PAGE);
        $this->assertFlashSays($client, 'no longer in front of you');
    }

    #[\Override]
    protected function artifactPaths(): array
    {
        return [...parent::artifactPaths(), $this->snapshotPath(), $this->importPath()];
    }

    /**
     * Nobody new. The three bladers are the ones `league()` put there, so a
     * fourth row means the screen invented somebody it was not told to.
     */
    private static function assertNobodyWasInvented(): void
    {
        PlayerFactory::assert()->count(3);
    }

    /**
     * The two bladers the fixture bracket is meant to reach, and the alias
     * that makes the second of them readable.
     */
    private function league(): void
    {
        PlayerFactory::createOne(['name' => self::KNOWN]);
        PlayerFactory::createOne(['name' => 'Giglio']);

        PlayerAliasFactory::createOne([
            'player' => PlayerFactory::createOne(['name' => self::ALIASED]),
            'alias' => self::ALIAS,
        ]);
    }

    /**
     * Every question the fixture asks, answered one of the two ways that
     * write something: one spelling pointed at a blader already on record, one
     * declared to be somebody new.
     *
     * @return array<string, string>
     */
    private function everyNameAnswered(): array
    {
        return [
            'decision['.self::UNKNOWN_KEY.']' => 'create',
            'decision['.self::MISSPELLED_KEY.']' => 'blader:'.PlayerFactory::find(['name' => 'Giglio'])->getId(),
        ];
    }

    private function fetchBracket(
        KernelBrowser $client,
        string $url = 'challonge.com/'.FakeChallonge::SLUG,
        string $date = self::DATE,
    ): Crawler {
        $this->submitFormAt($client, self::PAGE, [
            'bracket_import[challongeUrl]' => $url,
            'bracket_import[title]' => self::TITLE,
            'bracket_import[date]' => $date,
            'bracket_import[season]' => self::SEASON,
        ]);

        return $client->getCrawler();
    }

    /**
     * @param array<string, string> $answers the decision and order fields, which live outside
     *                                       the form's own namespace because there are as many
     *                                       of them as the bracket has questions
     */
    private function confirm(
        KernelBrowser $client,
        Crawler $crawler,
        array $answers,
        string $passphrase = self::ADMIN_PASSPHRASE,
    ): void {
        $form = $crawler->filter('form[name="bracket_confirm"]')->form();

        $form['bracket_confirm[passphrase]'] = $passphrase;

        $client->submit($form, $answers);
    }

    /**
     * @return list<string>
     */
    private function ledgerLines(): array
    {
        self::assertFileExists(self::ledgerPath());

        return array_values(array_filter(
            explode("\n", (string) file_get_contents(self::ledgerPath())),
            static fn (string $line): bool => '' !== trim($line),
        ));
    }

    private function snapshotPath(): string
    {
        return sprintf('%s/var/data/challonge/%s.json', self::projectDir(), FakeChallonge::SLUG);
    }

    private function importPath(): string
    {
        return sprintf(
            '%s/var/data/imports/%s-bracket-test-cup.txt',
            self::projectDir(),
            self::DATE,
        );
    }
}
