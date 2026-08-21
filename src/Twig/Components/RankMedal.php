<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A finishing position: a medal for the podium, plain digits below it.
 */
#[AsTwigComponent]
final class RankMedal
{
    public int $rank = 0;

    public function isPodium(): bool
    {
        return $this->rank >= 1 && $this->rank <= 3;
    }

    /**
     * Gold, silver, bronze. Only meaningful when isPodium() is true.
     */
    public function metal(): string
    {
        return match ($this->rank) {
            1 => 'gold',
            2 => 'silver',
            default => 'bronze',
        };
    }
}
