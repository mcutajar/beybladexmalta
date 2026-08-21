<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Knockout bonus points, or an em dash where none were earned.
 *
 * The leaderboard shows these as a chip and the two detail tables show them
 * bare, which is the only difference `chip` exists for.
 */
#[AsTwigComponent]
final class BonusPoints
{
    public int $value = 0;

    public bool $chip = false;
}
