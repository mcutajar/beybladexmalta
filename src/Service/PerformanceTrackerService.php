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
use App\Entity\PerformanceRound;
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

    public function record(PerformanceTracker $tracker, int $matchSequence, int $roundSequence, PerformanceBladeLane $lane, PerformanceResultValue $value): PerformanceScoreUpdate
    {
        $match = $this->match($tracker, $matchSequence);
        $round = $this->round($match, $roundSequence);
        $result = $round->resultFor($tracker->blade($lane));
        $wasComplete = $round->isComplete();
        $result->record($value);
        $tracker->touch();

        if ($match->isComplete() && !$round->isComplete()) {
            $match->reopen();
        }

        $this->flusher->flush();

        return new PerformanceScoreUpdate($result, !$wasComplete && $round->isComplete());
    }

    public function updateMatch(PerformanceTracker $tracker, int $sequence, PerformanceMatchData $data): void
    {
        $this->match($tracker, $sequence)->configure(
            $data->opponent,
            $data->finalScore,
            $data->stage,
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

    public function addRound(PerformanceTracker $tracker, int $matchSequence): PerformanceRound
    {
        $round = $this->newRound($tracker, $this->match($tracker, $matchSequence));
        $this->flusher->flush();

        return $round;
    }

    public function removeRound(PerformanceTracker $tracker, int $matchSequence, int $roundSequence): void
    {
        $match = $this->match($tracker, $matchSequence);
        if (1 >= $match->getRounds()->count()) {
            throw new \DomainException('A match must keep at least one round. Reset its results instead.');
        }

        $match->removeRound($this->round($match, $roundSequence));
        $tracker->touch();
        $this->flusher->flush();
    }

    public function finishMatch(PerformanceTracker $tracker, int $matchSequence): void
    {
        $this->match($tracker, $matchSequence)->finish();
        $tracker->touch();

        if ($this->shouldAddExpectedMatch($tracker)) {
            $this->newMatch($tracker);
        }

        $this->flusher->flush();
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

        $rounds = $first->getRounds()->toArray();
        $firstRound = array_shift($rounds);
        if (null === $firstRound) {
            $firstRound = $this->newRound($tracker, $first);
        }

        foreach ($firstRound->getResults() as $result) {
            $result->record(PerformanceResultValue::NotRecorded);
        }
        foreach ($rounds as $round) {
            $first->removeRound($round);
        }
        $first->reopen();
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
        $this->newRound($tracker, $match);

        return $match;
    }

    private function newRound(PerformanceTracker $tracker, PerformanceMatch $match): PerformanceRound
    {
        $round = new PerformanceRound($match, $match->nextRoundSequence());
        foreach ($tracker->getBlades() as $blade) {
            new PerformanceResult($round, $blade);
        }

        return $round;
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

    private function round(PerformanceMatch $match, int $sequence): PerformanceRound
    {
        return $match->round($sequence) ?? throw new \DomainException(sprintf('Round %d does not exist in match %d.', $sequence, $match->getSequence()));
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
