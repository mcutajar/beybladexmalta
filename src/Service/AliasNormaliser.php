<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Folds a display name down to the part of it that is identity rather than
 * spelling.
 *
 * This is the mechanical half of the alias problem, and it is worth being
 * precise about how far it goes. Across the eighteen captured brackets it
 * folds two hundred and seven distinct display names to a hundred and
 * twenty-nine — `MARKU LEGEND`, `MarkuLegend` and `Markulegend (invitation
 * pending)` all become `markulegend`, and `Rip_N_Burst` and `Rip N' Burst`
 * both become `ripnburst`. It does not fold `Anzjan` onto `Lanzjan`, and it
 * must not: `Obelix` and `Obelisk` are two letters apart and are two people,
 * so any rule loose enough to join the first pair joins the second one too.
 * Everything past this point is a stored alias somebody curated.
 *
 * Three things go, in order:
 *
 * 1. Case, which Challonge does not preserve consistently between the entrant
 *    list and the standings table of the same bracket.
 * 2. `(invitation pending)`, which Challonge appends to an entrant who was
 *    invited by email and never accepted — and which one bracket managed to
 *    append twice, so it is removed wherever it appears rather than once.
 * 3. Everything that is not a letter or a digit: spaces, hyphens, underscores
 *    and apostrophes are how one person spells their own name differently on
 *    two evenings.
 *
 * Letters keep their accents. Stripping those would fold names that Maltese
 * spelling distinguishes, which is the same mistake as folding Obelix.
 */
class AliasNormaliser
{
    /**
     * The suffix Challonge appends to an entrant who never accepted their
     * invitation. It is not part of anybody's name.
     */
    private const INVITATION_PENDING = '/\s*\(\s*invitation\s+pending\s*\)\s*/iu';

    private const PUNCTUATION = '/[^\p{L}\p{N}]+/u';

    public function normalise(string $name): string
    {
        $folded = mb_strtolower(trim($name));

        $folded = (string) preg_replace(self::INVITATION_PENDING, '', $folded);

        return (string) preg_replace(self::PUNCTUATION, '', $folded);
    }

    /**
     * Whether a string says anything at all once folded.
     *
     * `---` and `(invitation pending)` on its own both normalise to nothing,
     * and nothing is not a name to file an alias under.
     */
    public function isAName(string $name): bool
    {
        return '' !== $this->normalise($name);
    }
}
