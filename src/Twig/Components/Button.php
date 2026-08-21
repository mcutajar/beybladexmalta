<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The primary call to action, as a submit button or as a link.
 *
 * Passing `href` renders an anchor: something that navigates should still be
 * a link.
 */
#[AsTwigComponent]
final class Button
{
    public Tone $tone = Tone::Brand;

    /**
     * `gradient` is the hero treatment on the landing pages; forms use the
     * solid fill.
     */
    public ButtonVariant $variant = ButtonVariant::Solid;

    public ?string $href = null;

    public string $type = 'submit';

    public function mount(string $tone = 'brand', string $variant = 'solid'): void
    {
        $this->tone = Tone::from($tone);
        $this->variant = ButtonVariant::from($variant);
    }
}
