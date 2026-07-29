<?php

namespace App\Service;

enum RegisterSeasonPaymentResult
{
    case Registered;
    case AlreadyPaid;
    case SeasonNotFound;
}
