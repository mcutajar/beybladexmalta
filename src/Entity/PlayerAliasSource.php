<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Where an alias came from.
 *
 * An alias is an assertion that two spellings are one person, and the weight
 * of that assertion depends entirely on who made it. A person typing
 * `app:alias add` looked at both names; a row derived from a tournament that
 * was imported by hand inherits whatever care went into that import; a
 * Challonge account name was accepted from a suggestion rather than
 * recognised. When one of these turns out to be wrong — and one will — the
 * source is what says where to look for the others like it.
 */
enum PlayerAliasSource: string
{
    /** Typed by a person, who was looking at both spellings. */
    case Manual = 'manual';

    /** Derived from an event already imported under the blader's own name. */
    case Seeded = 'seeded';

    /** The Challonge account a bracket renders in place of the blader. */
    case ChallongeAccount = 'challonge-account';

    /**
     * The values, for a command's help and for an error that has to list them.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(
            static fn (self $source): string => $source->value,
            self::cases(),
        );
    }
}
