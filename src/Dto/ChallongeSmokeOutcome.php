<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What became of one thing the smoke check expected of a module page.
 *
 * `NotRun` is not a hedge. The page and the tournament store are what every
 * other expectation reads, so when one of those two goes there is nothing left
 * to look at — and reporting seven failures for one broken page would bury the
 * one that says what actually changed.
 */
enum ChallongeSmokeOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case NotRun = 'not run';
}
