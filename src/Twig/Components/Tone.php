<?php

declare(strict_types=1);

namespace App\Twig\Components;

/**
 * The palette a component can be tinted with.
 *
 * Components take the tone by name and look the Tailwind classes up in their
 * own template: Tailwind only scans `templates/`, so the class strings have to
 * live there rather than here.
 */
enum Tone: string
{
    case Brand = 'brand';
    case Flame = 'flame';
    case Positive = 'positive';
    case Heat = 'heat';
    case Info = 'info';
    case Neutral = 'neutral';
}
