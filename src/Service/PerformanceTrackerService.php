<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\CreatedPerformanceTracker;
use App\Dto\PerformanceBladeData;
use App\Dto\PerformanceBladeLane;
use App\Dto\PerformanceMatchData;
use App\Dto\PerformanceResultValue;
use App\Dto\PerformanceScoreUpdate;
use App\Dto\PerformanceTrackerData;
use App\Entity\PerformanceBlade;
use App\Entity\PerformanceMatch;
use App\Entity\PerformanceResult;
use App\Entity\PerformanceTracker;
use App\Repository\PerformanceTrackerRepository;

final class PerformanceTrackerService
{
    private const array COLOURS = ['', 'cyan', 'amber', 'red', 'emerald', 'blue', 'violet'];

    public function __construct(
        private PerformanceTrackerRepository $trackers,
        private FlusherInterface $flusher,
    ) {
    }

    public function create(PerformanceTrackerData $data): CreatedPerformanceTracker
    {
        $this->validate($data);
        $token = self::token(32);

        $tracker = new PerformanceTracker(
            hash('sha256', $token),
            self::token(24),
            $data->tournamentName,
            $data->heldOn,
            $data->playerName,
            $data->location,
            $data->expectedMatches,
        );

        foreach (PerformanceBladeLane::cases() as $index => $lane) {
            $this->newBlade($tracker, $lane, $data->blades[$index]);
        }

        $this->newMatch($tracker);
        $this->trackers->save($tracker);
        $this->flusher->flush();

        return new CreatedPerformanceTracker($tracker, $token);
    }

    public function update(PerformanceTracker $tracker, PerformanceTrackerData $data): void
    {
        $this->validate($data);
        $tracker->configure(
            $data->tournamentName,
            $data->heldOn,
            $data->playerName,
            $data->location,
            $data->expectedMatches,
        );

        foreach (PerformanceBladeLane::cases() as $index => $lane) {
            $blade = $data->blades[$index];
            $tracker->blade($lane)->configure($blade->displayName, $blade->blade, $blade->ratchet, $blade->bit, $blade->colour);
        }

        if ($this->shouldAddExpectedMatch($tracker)) {
            $this->newMatch($tracker);
        }

        $this->flusher->flush();
    }

    public function record(PerformanceTracker $tracker, int $sequence, PerformanceBladeLane $lane, PerformanceResultValue $value): PerformanceScoreUpdate
    {
        $match = $this->match($tracker, $sequence);
        $result = $match->resultFor($tracker->blade($lane));
        $wasComplete = $match->isComplete();
        $result->record($value);
        $tracker->touch();

        $stationChanged = $wasComplete !== $match->isComplete();
        if (!$wasComplete && $match->isComplete() && $this->shouldAddExpectedMatch($tracker)) {
            $this->newMatch($tracker);
            $stationChanged = true;
        }

        $this->flusher->flush();

        return new PerformanceScoreUpdate($result, $stationChanged);
    }

    public function updateMatch(PerformanceTracker $tracker, int $sequence, PerformanceMatchData $data): void
    {
        $this->match($tracker, $sequence)->configure(
            $data->opponent,
            $data->finalScore,
            $data->round,
            $data->notes,
            $data->playedAt,
        );
        $tracker->touch();
        $this->flusher->flush();
    }

    public function addMatch(PerformanceTracker $tracker): PerformanceMatch
    {
        $match = $this->newMatch($tracker);
        $this->flusher->flush();

        return $match;
    }

    public function removeMatch(PerformanceTracker $tracker, int $sequence): void
    {
        if (1 >= $tracker->getMatches()->count()) {
            throw new \DomainException('A tracker must keep at least one match. Reset it instead.');
        }

        $tracker->removeMatch($this->match($tracker, $sequence));
        $this->flusher->flush();
    }

    public function reset(PerformanceTracker $tracker): void
    {
        $matches = $tracker->getMatches()->toArray();
        $first = array_shift($matches);
        if (null === $first) {
            $this->newMatch($tracker);
            $this->flusher->flush();

            return;
        }

        foreach ($first->getResults() as $result) {
            $result->record(PerformanceResultValue::NotRecorded);
        }
        $first->configure(null, null, null, null, null);

        foreach ($matches as $match) {
            $tracker->removeMatch($match);
        }
        $this->flusher->flush();
    }

    public function duplicate(PerformanceTracker $tracker): CreatedPerformanceTracker
    {
        return $this->create(PerformanceTrackerData::fromTracker($tracker));
    }

    private function newBlade(PerformanceTracker $tracker, PerformanceBladeLane $lane, PerformanceBladeData $data): void
    {
        new PerformanceBlade(
            $tracker,
            $lane,
            $data->displayName,
            $data->blade,
            $data->ratchet,
            $data->bit,
            $data->colour,
        );
    }

    private function newMatch(PerformanceTracker $tracker): PerformanceMatch
    {
        $match = new PerformanceMatch($tracker, $tracker->nextMatchSequence());
        foreach ($tracker->getBlades() as $blade) {
            new PerformanceResult($match, $blade);
        }

        return $match;
    }

    private function shouldAddExpectedMatch(PerformanceTracker $tracker): bool
    {
        if ($tracker->getMatches()->count() >= $tracker->getExpectedMatches()) {
            return false;
        }

        foreach ($tracker->getMatches() as $match) {
            if (!$match->isComplete()) {
                return false;
            }
        }

        return true;
    }

    private function match(PerformanceTracker $tracker, int $sequence): PerformanceMatch
    {
        return $tracker->match($sequence) ?? throw new \DomainException(sprintf('Match %d does not exist.', $sequence));
    }

    private function validate(PerformanceTrackerData $data): void
    {
        if ('' === trim($data->tournamentName) || '' === trim($data->playerName) || null === $data->heldOn) {
            throw new \DomainException('Tournament name, date and player name are required.');
        }
        if (1 > $data->expectedMatches || 99 < $data->expectedMatches) {
            throw new \DomainException('Expected matches must be between 1 and 99.');
        }
        if (3 !== count($data->blades)) {
            throw new \DomainException('Configure exactly three blades.');
        }
        foreach ($data->blades as $blade) {
            if ('' === trim($blade->displayName)) {
                throw new \DomainException('Each blade needs a display name.');
            }
            if (!in_array($blade->colour, self::COLOURS, true)) {
                throw new \DomainException('Choose a listed blade colour.');
            }
        }
    }

    private static function token(int $bytes): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
