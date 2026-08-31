<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Knockout bonus points, or an em dash where none were earned.
 *
 * The leaderboard and tournament summaries show these as a chip; detailed
 * contribution tables can keep the quieter bare value.
 */
#[AsTwigComponent]
final class BonusPoints
{
    public int $value = 0;

    public bool $chip = false;
}
