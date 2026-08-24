<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * Which part of a bracket a stage is.
 *
 * A two-stage event has a group stage — the Swiss rounds everybody plays —
 * and a final stage, the cut. A one-stage event has neither: it is the whole
 * tournament, so it is neither a group nor a final.
 */
enum ChallongeStageKind: string
{
    case Group = 'group';
    case Final = 'final';
    case Single = 'single';
}
