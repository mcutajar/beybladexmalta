<?php

declare(strict_types=1);

namespace App\Twig\Components;

enum CardVariant: string
{
    /** Flat surface. The default, and what tables sit on. */
    case Solid = 'solid';
    /** Fades into the canvas. Used where a card ends a page. */
    case Gradient = 'gradient';
    /** Translucent and blurred. Used for the widest sections. */
    case Glass = 'glass';
}
