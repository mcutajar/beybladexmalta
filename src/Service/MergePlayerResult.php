<?php

declare(strict_types=1);

namespace App\Service;

enum MergePlayerResult
{
    case Ready;
    case Merged;
    case AlreadyMerged;
    case PlayerNotFound;
    case SamePlayer;
    case Conflict;
}
