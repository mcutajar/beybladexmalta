<?php

declare(strict_types=1);

namespace App\Service;

/**
 * What a finishing rank is worth.
 *
 * The league scores the top ten on the Formula One matrix and nothing below
 * it. Kept on its own because two things award it now: an import, and the
 * claim that attaches bladers to a team long after their event was imported.
 * Two copies of these ten numbers would be two places to get them wrong.
 */
class F1Points
{
    private const array MATRIX = [
        1 => 25, 2 => 20, 3 => 15, 4 => 12, 5 => 10,
        6 => 8,  7 => 6,  8 => 4,  9 => 2,  10 => 1,
    ];

    public function forRank(int $rank): int
    {
        return self::MATRIX[$rank] ?? 0;
    }
}
