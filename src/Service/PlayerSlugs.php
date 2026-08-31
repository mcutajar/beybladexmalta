<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Repository\PlayerRepository;

/**
 * The one thing that gives a blader their public URL.
 *
 * A slug is assigned once, when the blader is put on record, and persisted —
 * never recalculated from the current display name. That is the whole point:
 * `Player::$name` is corrected from time to time, and a URL that moved every
 * time somebody fixed a capital letter would be a URL nobody could share.
 *
 * The derivation is deliberately meagre — lowercase, ASCII where a transliteration
 * exists, everything else collapsed to a single hyphen. It is not aliasing.
 * `AliasNormaliser` is what decides two spellings are the same person, and it
 * strips far more than this does; borrowing it here would hand `Steve V.` and
 * `SteveV` the same URL, which the unique index would then refuse at a moment
 * nobody can act on.
 *
 * A collision is resolved by numbering rather than by refusing, because a
 * blader whose punctuation happens to fold onto somebody else's is still a
 * blader who has to exist. Names are unique, so the numbered slug belongs to
 * exactly one person and stays theirs.
 */
final class PlayerSlugs
{
    /**
     * What a blader with no usable characters in their name gets. Reachable:
     * a display name of nothing but punctuation is refused elsewhere, but a
     * name written entirely in a script with no transliteration is not.
     */
    private const string FALLBACK = 'blader';

    public function __construct(
        private readonly PlayerRepository $players,
    ) {
    }

    /**
     * Gives a blader a slug, if they do not have one yet.
     *
     * Idempotent on purpose: every site that creates a `Player` calls this, and
     * a re-save must not move a URL that is already public.
     */
    public function assign(Player $player): void
    {
        try {
            $player->getSlug();

            return;
        } catch (\Error) {
            // Uninitialised, which is a blader who has just been constructed.
        }

        $player->setSlug($this->free($this->derive($player->getName())));
    }

    /**
     * The first slug in the series nobody holds.
     */
    private function free(string $base): string
    {
        $slug = $base;
        $suffix = 1;

        while (null !== $this->players->findOneBy(['slug' => $slug])) {
            ++$suffix;
            $slug = sprintf('%s-%d', $base, $suffix);
        }

        return $slug;
    }

    private function derive(string $name): string
    {
        $slug = mb_strtolower(trim($name));

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);

        if (false !== $transliterated) {
            $slug = $transliterated;
        }

        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($slug));
        $slug = trim($slug, '-');

        return '' === $slug ? self::FALLBACK : $slug;
    }
}
