<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * A raised surface. Everything on the site that is not prose sits in one.
 *
 * `size` moves padding and corner radius together so cards stay on a single
 * scale instead of each page inventing its own.
 */
#[AsTwigComponent]
final class Card
{
    public CardVariant $variant = CardVariant::Solid;

    public CardSize $size = CardSize::Md;

    public function mount(string $variant = 'solid', string $size = 'md'): void
    {
        $this->variant = CardVariant::from($variant);
        $this->size = CardSize::from($size);
    }
}
