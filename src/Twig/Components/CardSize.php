<?php

declare(strict_types=1);

namespace App\Twig\Components;

enum CardSize: string
{
    case Sm = 'sm';
    case Md = 'md';
    case Lg = 'lg';
}
