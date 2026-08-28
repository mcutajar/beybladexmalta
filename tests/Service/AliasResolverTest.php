<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\AliasMatch;
use App\Dto\AliasResolution;
use App\Dto\AliasSuggestion;
use App\Dto\AliasSuggestionReason;
use App\Entity\Player;
use App\Service\AliasResolver;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerAliasRejectionFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Support\ServiceTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The resolver, put to the spellings that actually caused the trouble.
 *
 * Every name in here is one a captured bracket really used. The point of the
 * ticket is the difference between the two halves of this class: the first
 * half is names that reach a blader, the second is names that reach a
 * question — and there is no third half where a name quietly becomes a
 * seventy-seventh blader.
 */
#[ResetDatabase]
final class AliasResolverTest extends ServiceTestCase
{
    private AliasResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = $this->service(AliasResolver::class);
    }

    public function testABladerIsFoundUnderTheirOwnNameHoweverItIsSpelled(): void
    {
        $this->blader('Il-Karm');

        $resolution = $this->resolver->resolve('  IL_KARM  ');

        self::assertSame('Il-Karm', $resolution->player?->getName());
        self::assertSame(AliasMatch::BladerName, $resolution->match);
    }

    public function testAnInvitationNobodyAcceptedIsNotPartOfTheName(): void
    {
        $this->blader('Markinu');

        $resolution = $this->resolver->resolve('markinu (invitation pending)');

        self::assertSame('Markinu', $resolution->player?->getName());
        self::assertSame(AliasMatch::BladerName, $resolution->match);
    }

    /**
     * The three the ticket names: none of them is reachable by any amount of
     * string handling, and all three are one row in a table.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function curatedSpellings(): iterable
    {
        yield 'a dropped article' => ['Anzjan', 'Lanzjan'];
        yield 'a shouted nickname' => ['KARM', 'Il-Karm'];
        yield 'the definite article, spaced' => ['the rizzler', 'Rizzler'];
    }

    #[DataProvider('curatedSpellings')]
    public function testAStoredAliasReachesTheBlader(string $spelling, string $bladerName): void
    {
        $blader = $this->blader($bladerName);
        $this->alias($blader, $spelling);

        $resolution = $this->resolver->resolve($spelling);

        self::assertSame($bladerName, $resolution->player?->getName());
        self::assertSame(AliasMatch::Alias, $resolution->match);
    }

    /**
     * An alias is stored normalised, so it answers for every spelling of
     * itself rather than only the one that was typed.
     */
    public function testAnAliasAnswersForEverySpellingOfItself(): void
    {
        $this->alias($this->blader('Rizzler'), 'the rizzler');

        self::assertSame('Rizzler', $this->resolver->resolve('The_Rizzler')->player?->getName());
        self::assertSame('Rizzler', $this->resolver->resolve('THE RIZZLER')->player?->getName());
    }

    /**
     * The pair the whole ticket turns on. Two letters apart, two people, and
     * the only safe thing to do is ask.
     */
    public function testObeliskIsOfferedRatherThanMergedIntoObelix(): void
    {
        $this->blader('Obelix');

        $resolution = $this->resolver->resolve('Obelisk');

        self::assertFalse($resolution->isResolved());
        self::assertSame(['Obelix'], self::suggested($resolution));
        self::assertSame(AliasSuggestionReason::Spelling, $resolution->suggestions[0]->reason);
        self::assertSame(2, $resolution->suggestions[0]->distance);
    }

    public function testARejectedSuggestionIsNeverOfferedAgain(): void
    {
        $steve = $this->blader('Steve');
        $this->blader('Steve X');
        PlayerAliasRejectionFactory::createOne(['player' => $steve, 'spelling' => 'Steve V.']);

        self::assertSame(['Steve X'], self::suggested($this->resolver->resolve('STEVE_V')));
    }

    public function testARejectionOnlyAppliesToThatSpellingAndBladerPair(): void
    {
        $steve = $this->blader('Steve');
        $this->blader('Steve X');
        PlayerAliasRejectionFactory::createOne(['player' => $steve, 'spelling' => 'Steve V.']);

        self::assertContains('Steve', self::suggested($this->resolver->resolve('Steve W.')));
        self::assertContains('Steve X', self::suggested($this->resolver->resolve('Steve V.')));
    }

    /**
     * The rule underneath everything else. A name that reaches nobody comes
     * back as a question, and the table it was asked about is exactly as long
     * afterwards as it was before.
     */
    public function testAnUnknownNameNeverBecomesABlader(): void
    {
        $this->blader('Obelix');

        $resolution = $this->resolver->resolve('Somebody New');

        self::assertFalse($resolution->isResolved());
        self::assertNull($resolution->player);
        self::assertStringContainsString('"Somebody New" is nobody the league knows', $resolution->problem());

        PlayerFactory::assert()->count(1);
        PlayerAliasFactory::assert()->empty();
    }

    public function testANameWithNothingInItReachesNobodyAndSuggestsNobody(): void
    {
        $this->blader('Obelix');

        $resolution = $this->resolver->resolve('(invitation pending)');

        self::assertFalse($resolution->isResolved());
        self::assertSame([], $resolution->suggestions);
    }

    /**
     * The suffixes, prefixes and trailing numbers that make up most of the
     * real corpus.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function sharedStems(): iterable
    {
        yield 'a suffix' => ['BladerZMLT', 'BladerZ'];
        yield 'a prefix' => ['IlBelti', 'Belti'];
        yield 'a number' => ['Guzman93', 'Guzman'];
        yield 'an article and a year' => ['The_Rizzler2016', 'Rizzler'];
    }

    #[DataProvider('sharedStems')]
    public function testASpellingBuiltOnABladersNameIsOffered(string $spelling, string $bladerName): void
    {
        $this->blader($bladerName);

        $resolution = $this->resolver->resolve($spelling);

        self::assertFalse($resolution->isResolved(), 'A resemblance is a suggestion, never a match.');
        self::assertSame([$bladerName], self::suggested($resolution));
    }

    /**
     * The half of the problem edit distance cannot see. `bladerzmlt` is three
     * edits from `bladerz` and `therizzler2016` is six from `rizzler`; both
     * are obvious to anybody who was there, and neither is within any
     * threshold loose enough to be safe.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function stemsOnly(): iterable
    {
        yield 'a suffix' => ['BladerZMLT', 'BladerZ', 'bladerz'];
        yield 'an article and a year' => ['The_Rizzler2016', 'Rizzler', 'rizzler'];
    }

    #[DataProvider('stemsOnly')]
    public function testTheReasonGivenIsTheSharedStem(string $spelling, string $bladerName, string $stem): void
    {
        $this->blader($bladerName);

        $suggestion = $this->resolver->resolve($spelling)->suggestions[0];

        self::assertSame(AliasSuggestionReason::PartOfAKnownName, $suggestion->reason);
        self::assertSame(sprintf('shares a stem with "%s"', $stem), $suggestion->because());
    }

    /**
     * A blader who linked their Challonge account is rendered as that account
     * in the standings while every match in the same bracket says their real
     * name. The account is often the only string that reaches anybody — and it
     * is still only a suggestion, because an account is a login and a house
     * can share one.
     */
    public function testAChallongeAccountIsOfferedAndNeverApplied(): void
    {
        $this->alias($this->blader('Sanya'), 'Sanya0207');

        $resolution = $this->resolver->resolve('legion', 'Sanya0207');

        self::assertFalse($resolution->isResolved());
        self::assertSame(['Sanya'], self::suggested($resolution));
        self::assertSame(AliasSuggestionReason::ChallongeAccount, $resolution->suggestions[0]->reason);
    }

    public function testTheAccountIsOfferedAheadOfAMereSpelling(): void
    {
        $this->blader('Sanya');
        $this->blader('Legion7');

        $resolution = $this->resolver->resolve('legion', 'SANYA');

        self::assertSame(['Sanya', 'Legion7'], self::suggested($resolution));
    }

    /**
     * Two bladers whose names fold together settle nothing. The table is
     * unique on the raw name, so both rows can exist; picking one here would
     * be right half the time and silent the rest.
     */
    public function testTwoBladersWhoFoldTogetherResolveToNeither(): void
    {
        $this->blader("Rip N' Burst");
        $this->blader('Ripnburst');

        $resolution = $this->resolver->resolve('Rip_N_Burst');

        self::assertFalse($resolution->isResolved());
        self::assertTrue($resolution->isAmbiguous());
        self::assertSame(["Rip N' Burst", 'Ripnburst'], self::suggested($resolution));
        self::assertStringContainsString(
            '"Rip_N_Burst" is how more than one blader is already spelled',
            $resolution->problem(),
        );
    }

    /**
     * A collision is not a near miss, and must not be reported as one. Both
     * claimants are an exact hit, so "0 edits from" would be both meaningless
     * and the one distance the suggestion vocabulary reserves for an account.
     */
    public function testACollisionSaysWhatItIsRatherThanCountingEdits(): void
    {
        $this->blader("Rip N' Burst");
        $this->blader('Ripnburst');

        $suggestion = $this->resolver->resolve('Rip_N_Burst')->suggestions[0];

        self::assertSame(AliasSuggestionReason::SpelledTheSameWay, $suggestion->reason);
        self::assertSame('is already spelled "ripnburst"', $suggestion->because());
    }

    /**
     * The shortlist for a collision is the only one that does not go through a
     * distance, so it is the one that would otherwise come out in whatever
     * order `findAll()` returned. Alphabetical by blader, every time.
     */
    public function testACollisionShortlistIsOrderedTheSameWayEveryTime(): void
    {
        $this->blader('Ripnburst');
        $this->blader("Rip N' Burst");

        self::assertSame(
            ["Rip N' Burst", 'Ripnburst'],
            self::suggested($this->resolver->resolve('Rip_N_Burst')),
        );
    }

    /**
     * Where a collision is what stops the row being read, an exact hit on a
     * linked account is the one fact that says which side of it was meant. It
     * is offered first, and it is still only offered.
     */
    public function testAnAccountIsOfferedFirstAgainstACollision(): void
    {
        $this->blader("Rip N' Burst");
        $this->blader('Ripnburst');
        $this->alias($this->blader('Sanya'), 'RNBmlt');

        $resolution = $this->resolver->resolve('Rip_N_Burst', 'RNBmlt');

        self::assertTrue($resolution->isAmbiguous());
        self::assertSame(['Sanya', "Rip N' Burst", 'Ripnburst'], self::suggested($resolution));
        self::assertSame(AliasSuggestionReason::ChallongeAccount, $resolution->suggestions[0]->reason);
    }

    /**
     * The hole the one-namespace rule does not cover. AliasService refuses to
     * file an alias onto a blader's name, but bladers also arrive by being
     * invented from a placement list — so `KARM` can be created as a blader
     * long after `karm` was filed against Il-Karm. Preferring either one
     * splits somebody's career across two rows and says nothing; both come
     * back instead, which is what makes it visible.
     */
    public function testABladerCreatedLaterCannotShadowAnAlias(): void
    {
        $this->alias($this->blader('Il-Karm'), 'KARM');

        $shadow = $this->blader('KARM');

        $resolution = $this->resolver->resolve('karm');

        self::assertFalse($resolution->isResolved());
        self::assertTrue($resolution->isAmbiguous());
        self::assertSame(['Il-Karm', 'KARM'], self::suggested($resolution));
        self::assertNotNull($shadow->getId());
    }

    /**
     * A blader's own name and an alias are two spellings in one namespace, not
     * two tables with a precedence between them. Each says which it came from,
     * because when a result looks wrong the alias row is what to go and read.
     */
    public function testAResolutionSaysWhichSpellingReachedTheBlader(): void
    {
        $karm = $this->blader('Il-Karm');
        $this->alias($karm, 'KARM');

        self::assertSame(AliasMatch::BladerName, $this->resolver->resolve('il karm')->match);
        self::assertSame(AliasMatch::Alias, $this->resolver->resolve('karm')->match);
    }

    public function testAWholeBracketIsAnsweredNameForName(): void
    {
        $this->alias($this->blader('Lanzjan'), 'Anzjan');
        $this->blader('Obelix');

        $resolutions = $this->resolver->resolveAll([
            ['name' => 'ANZJAN'],
            ['name' => 'Obelisk'],
            ['name' => 'obelix'],
        ]);

        self::assertSame(
            ['Lanzjan', null, 'Obelix'],
            array_map(
                static fn (AliasResolution $resolution): ?string => $resolution->player?->getName(),
                $resolutions,
            ),
        );
    }

    /**
     * A standings row carries its own linked account, so the bulk call has to
     * carry one per name. A shape that could not would be exactly the shape
     * #53 needs and would drop the suggestion that reaches most of the rows.
     */
    public function testEachNameInABracketCarriesItsOwnAccount(): void
    {
        $this->alias($this->blader('Sanya'), 'Sanya0207');

        $resolutions = $this->resolver->resolveAll([
            ['name' => 'legion', 'account' => 'Sanya0207'],
            ['name' => 'nobody at all'],
        ]);

        self::assertSame(['Sanya'], self::suggested($resolutions[0]));
        self::assertSame(AliasSuggestionReason::ChallongeAccount, $resolutions[0]->suggestions[0]->reason);
        self::assertSame([], self::suggested($resolutions[1]));
    }

    private function blader(string $name): Player
    {
        return PlayerFactory::createOne(['name' => $name]);
    }

    private function alias(Player $player, string $spelling): void
    {
        PlayerAliasFactory::createOne(['player' => $player, 'alias' => $spelling]);
    }

    /**
     * @return list<string>
     */
    private static function suggested(AliasResolution $resolution): array
    {
        return array_map(
            static fn (AliasSuggestion $suggestion): string => $suggestion->player->getName(),
            $resolution->suggestions,
        );
    }
}
