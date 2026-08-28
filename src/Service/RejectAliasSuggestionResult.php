<?php

declare(strict_types=1);

namespace App\Service;

enum RejectAliasSuggestionResult
{
    case Rejected;
    case AlreadyRejected;
    case Allowed;
    case NotRejected;
    case BladerNotFound;
    case BladerIsAmbiguous;
    case NotAName;
}
