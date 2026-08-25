<?php

declare(strict_types=1);

namespace App\Service;

enum RemoveAliasResult
{
    case Removed;
    case NotFound;
    case NotAName;
}
