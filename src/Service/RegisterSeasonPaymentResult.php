<?php

declare(strict_types=1);

namespace App\Service;

enum RegisterSeasonPaymentResult
{
    case Registered;
    case AlreadyPaid;
    case SeasonNotFound;
}
