<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AliasNormaliser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The mechanical half of the alias problem, and only that half.
 *
 * Half these cases exist to prove the fold happens, and half to prove it stops
 * where it does. `Obelisk` staying separate from `Obelix` is not a gap: it is
 * the reason the stored table exists at all.
 */
final class AliasNormaliserTest extends TestCase
{
    private AliasNormaliser $normaliser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normaliser = new AliasNormaliser();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function spellings(): iterable
    {
        yield 'case' => ['MARKULEGEND', 'markulegend'];
        yield 'spacing' => ['MARKU LEGEND', 'markulegend'];
        yield 'a stray space' => ['GERADA 46', 'gerada46'];
        yield 'surrounding whitespace' => ["  Belti\n", 'belti'];
        yield 'underscores' => ['Rip_N_Burst', 'ripnburst'];
        yield 'an apostrophe' => ["Rip N' Burst", 'ripnburst'];
        yield 'a hyphen' => ['L-anzjan', 'lanzjan'];
        yield 'an ampersand' => ['RIP & BURST', 'ripburst'];
        yield 'an invitation nobody accepted' => ['markinu (invitation pending)', 'markinu'];
        yield 'an invitation appended twice' => ['Myers6 (invitation pending) (invitation pending)', 'myers6'];
        yield 'an invitation in the middle' => ['sAnJa (invitation pending)', 'sanja'];
        yield 'digits, which are part of a name' => ['Sanya0207', 'sanya0207'];
        yield 'an accent, which is part of a name' => ['Ġanni', 'ġanni'];
        yield 'nothing but punctuation' => ['---', ''];
        yield 'nothing but an invitation' => ['(invitation pending)', ''];
    }

    #[DataProvider('spellings')]
    public function testItFoldsAwayEverythingThatIsNotIdentity(string $spelling, string $normalised): void
    {
        self::assertSame($normalised, $this->normaliser->normalise($spelling));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function differentBladers(): iterable
    {
        yield 'two letters apart, and two people' => ['Obelix', 'Obelisk'];
        yield 'a plain typo' => ['Markulegend', 'MaarkuLegend'];
        yield 'a missing article' => ['Lanzjan', 'Anzjan'];
        yield 'a suffix' => ['BladerZ', 'BladerZMLT'];
        yield 'a doubled letter' => ['Sk3lli', 'Sk3llii'];
    }

    /**
     * The line this class does not cross. Every pair here is either one blader
     * or two, and nothing about the strings says which — so the fold leaves
     * them apart and a person decides.
     */
    #[DataProvider('differentBladers')]
    public function testItNeverJoinsTwoSpellingsOnItsOwn(string $one, string $other): void
    {
        self::assertNotSame(
            $this->normaliser->normalise($one),
            $this->normaliser->normalise($other),
            'Normalisation guessed at something only the alias table may say.',
        );
    }

    public function testAStringWithNoNameInItIsNotAName(): void
    {
        self::assertFalse($this->normaliser->isAName('(invitation pending)'));
        self::assertFalse($this->normaliser->isAName('   '));
        self::assertTrue($this->normaliser->isAName('KARM'));
    }
}
