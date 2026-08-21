<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * One notice. Flash messages render through this via <twig:Flashes>.
 */
#[AsTwigComponent]
final class Alert
{
    public Tone $tone = Tone::Brand;

    public function mount(string $tone = 'brand'): void
    {
        $this->tone = Tone::from($tone);
    }
}
