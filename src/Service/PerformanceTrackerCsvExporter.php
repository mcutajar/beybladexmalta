<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PerformanceTracker;

final class PerformanceTrackerCsvExporter
{
    public function export(PerformanceTracker $tracker): string
    {
        $stream = fopen('php://temp', 'w+');
        if (false === $stream) {
            throw new \RuntimeException('Could not open the CSV buffer.');
        }

        $header = ['match', 'round', 'opponent', 'final score', 'stage / placement', 'notes', 'played at'];
        foreach ($tracker->getBlades() as $blade) {
            $header[] = sprintf('%s (%s)', $blade->getDisplayName(), $blade->getLane()->value);
        }
        fputcsv($stream, $header, ',', '"', '');

        foreach ($tracker->getMatches() as $match) {
            foreach ($match->getRounds() as $round) {
                $row = [
                    (string) $match->getSequence(),
                    (string) $round->getSequence(),
                    $match->getOpponent() ?? '',
                    $match->getFinalScore() ?? '',
                    $match->getStage() ?? '',
                    $match->getNotes() ?? '',
                    $match->getPlayedAt()?->format(\DateTimeInterface::ATOM) ?? '',
                ];
                foreach ($tracker->getBlades() as $blade) {
                    $row[] = $round->resultFor($blade)->getValue()->csvValue();
                }
                fputcsv($stream, $row, ',', '"', '');
            }
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if (false === $csv) {
            throw new \RuntimeException('Could not read the CSV buffer.');
        }

        return $csv;
    }
}
