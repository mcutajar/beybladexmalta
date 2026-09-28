<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PerformanceBladeSummary;
use App\Dto\PerformanceResultValue;
use App\Dto\PerformanceTrackerSummary;
use App\Entity\PerformanceTracker;

final class PerformanceTrackerPresenter
{
    public function summarise(PerformanceTracker $tracker): PerformanceTrackerSummary
    {
        $bladeSummaries = [];
        $total = 0;
        $appearances = 0;

        foreach ($tracker->getBlades() as $blade) {
            $bladeTotal = 0;
            $bladeAppearances = 0;
            $positive = 0;
            $negative = 0;
            $zero = 0;
            $unused = 0;
            $best = null;
            $history = [];

            foreach ($tracker->getMatches() as $match) {
                foreach ($match->getRounds() as $round) {
                    $value = $round->resultFor($blade)->getValue();
                    $history[] = [
                        'match' => $match->getSequence(),
                        'round' => $round->getSequence(),
                        'value' => $value,
                    ];

                    if (PerformanceResultValue::Unused === $value) {
                        ++$unused;
                    }

                    $score = $value->score();
                    if (null === $score) {
                        continue;
                    }

                    $bladeTotal += $score;
                    ++$bladeAppearances;
                    $best = null === $best ? $score : max($best, $score);

                    if (0 < $score) {
                        ++$positive;
                    } elseif (0 > $score) {
                        ++$negative;
                    } else {
                        ++$zero;
                    }
                }
            }

            $bladeSummaries[] = new PerformanceBladeSummary(
                $blade,
                $bladeTotal,
                $bladeAppearances,
                $positive,
                $negative,
                $zero,
                $unused,
                $best,
                $history,
            );
            $total += $bladeTotal;
            $appearances += $bladeAppearances;
        }

        $completed = 0;
        $recorded = 0;
        foreach ($tracker->getMatches() as $match) {
            if ($match->isComplete()) {
                ++$completed;
            }
            foreach ($match->getRounds() as $round) {
                foreach ($round->getResults() as $result) {
                    if (PerformanceResultValue::NotRecorded !== $result->getValue()) {
                        ++$recorded;
                        continue 3;
                    }
                }
            }
        }

        return new PerformanceTrackerSummary($bladeSummaries, $total, $appearances, $completed, $recorded);
    }
}
