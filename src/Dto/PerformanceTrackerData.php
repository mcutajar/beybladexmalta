<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\PerformanceTracker;

final class PerformanceTrackerData
{
    /** @param list<PerformanceBladeData> $blades */
    public function __construct(
        public string $tournamentName = '',
        public ?\DateTimeImmutable $heldOn = null,
        public string $playerName = '',
        public string $location = '',
        public int $expectedMatches = 5,
        public array $blades = [],
    ) {
        if ([] === $this->blades) {
            $this->blades = [
                new PerformanceBladeData('Blade A'),
                new PerformanceBladeData('Blade B'),
                new PerformanceBladeData('Blade C'),
            ];
        }
    }

    public static function fromTracker(PerformanceTracker $tracker): self
    {
        $blades = [];
        foreach ($tracker->getBlades() as $blade) {
            $blades[] = new PerformanceBladeData(
                $blade->getDisplayName(),
                $blade->getBlade(),
                $blade->getRatchet(),
                $blade->getBit(),
                $blade->getColour() ?? '',
            );
        }

        return new self(
            $tracker->getTournamentName(),
            $tracker->getHeldOn(),
            $tracker->getPlayerName(),
            $tracker->getLocation() ?? '',
            $tracker->getExpectedMatches(),
            $blades,
        );
    }
}
