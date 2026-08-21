<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The uppercase pill above a heading, and the small tag inside a table row.
 */
#[AsTwigComponent]
final class Badge
{
    public Tone $tone = Tone::Brand;

    /**
     * `md` is the eyebrow above a page or card heading; `sm` is the inline tag
     * that sits in a table cell.
     */
    public BadgeSize $size = BadgeSize::Md;

    public function mount(string $tone = 'brand', string $size = 'md'): void
    {
        $this->tone = Tone::from($tone);
        $this->size = BadgeSize::from($size);
    }
}
