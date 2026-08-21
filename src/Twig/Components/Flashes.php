<?php

declare(strict_types=1);

namespace App\Twig\Components;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders whatever the request left in the flash bag.
 */
#[AsTwigComponent]
final class Flashes
{
    /**
     * Flash labels are free-form strings, so a label the app has no tone for
     * falls back to a plain notice rather than failing to render.
     */
    public function toneFor(string $label): string
    {
        return match ($label) {
            'success' => Tone::Positive->value,
            'warning' => Tone::Flame->value,
            'error' => Tone::Heat->value,
            default => Tone::Brand->value,
        };
    }
}
