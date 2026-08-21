<?php

declare(strict_types=1);

namespace App\Twig\Components;

enum ButtonVariant: string
{
    case Solid = 'solid';
    case Gradient = 'gradient';
}
