<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\PerformanceBladeData;
use App\Dto\PerformanceBladeLane;
use App\Dto\PerformanceResultValue;
use App\Dto\PerformanceTrackerData;
use App\Service\PerformanceTrackerPresenter;
use App\Service\PerformanceTrackerService;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Test\ResetDatabase;

final class PerformanceTrackerServiceTest extends ServiceTestCase
{
    use ResetDatabase;

    public function testItCreatesExactlyThreeStableLanesAndOneBlankMatch(): void
    {
        $created = $this->trackerService()->create($this->trackerData());
        $tracker = $created->tracker;

        self::assertNotSame('', $created->editToken);
        self::assertCount(3, $tracker->getBlades());
        self::assertSame(['A', 'B', 'C'], array_map(
            static fn ($blade): string => $blade->getLane()->value,
            $tracker->getBlades()->toArray(),
        ));
        self::assertCount(1, $tracker->getMatches());
        self::assertCount(3, $tracker->match(1)?->getResults());
        self::assertFalse($tracker->match(1)?->isComplete());
    }

    public function testTotalsCountsAndAveragesKeepUnusedAndNotRecordedDistinct(): void
    {
        $service = $this->trackerService();
        $tracker = $service->create($this->trackerData(expectedMatches: 2))->tracker;

        $service->record($tracker, 1, PerformanceBladeLane::A, PerformanceResultValue::PlusThree);
        $service->record($tracker, 1, PerformanceBladeLane::B, PerformanceResultValue::Unused);
        $update = $service->record($tracker, 1, PerformanceBladeLane::C, PerformanceResultValue::Zero);

        self::assertTrue($update->stationChanged);
        self::assertNotNull($tracker->match(2), 'Completing the only match should open the next expected match.');

        $service->record($tracker, 2, PerformanceBladeLane::A, PerformanceResultValue::MinusOne);
        $service->record($tracker, 2, PerformanceBladeLane::C, PerformanceResultValue::PlusTwo);

        $summary = $this->presenter()->summarise($tracker);
        $bladeA = $summary->forLane(PerformanceBladeLane::A);
        $bladeB = $summary->forLane(PerformanceBladeLane::B);

        self::assertSame(4, $summary->total);
        self::assertSame(4, $summary->appearances);
        self::assertSame(1.0, $summary->average());
        self::assertSame(2, $bladeA->total);
        self::assertSame(2, $bladeA->appearances);
        self::assertSame(1, $bladeA->positive);
        self::assertSame(1, $bladeA->negative);
        self::assertSame(3, $bladeA->best);
        self::assertSame(1, $bladeB->unused);
        self::assertSame(0, $bladeB->appearances);
        self::assertNull($bladeB->average());
        self::assertSame(1, $summary->completedMatches);
        self::assertSame(2, $summary->recordedMatches);
        self::assertSame(PerformanceResultValue::NotRecorded, $tracker->match(2)->resultFor($tracker->blade(PerformanceBladeLane::B))->getValue());
    }

    public function testRemovingAndAddingMatchesNeverRenumbersTheOthers(): void
    {
        $service = $this->trackerService();
        $tracker = $service->create($this->trackerData())->tracker;

        $service->addMatch($tracker);
        $service->addMatch($tracker);
        $service->removeMatch($tracker, 2);
        $service->addMatch($tracker);

        self::assertSame([1, 3, 4], array_values(array_map(
            static fn ($match): int => $match->getSequence(),
            $tracker->getMatches()->toArray(),
        )));
    }

    public function testUpdatingSetupNeverDiscardsExistingMatches(): void
    {
        $service = $this->trackerService();
        $tracker = $service->create($this->trackerData(expectedMatches: 4))->tracker;
        $service->addMatch($tracker);
        $service->record($tracker, 1, PerformanceBladeLane::A, PerformanceResultValue::PlusTwo);

        $updated = $this->trackerData(expectedMatches: 1);
        $updated->tournamentName = 'Renamed event';
        $updated->blades[0]->displayName = 'New lane name';
        $service->update($tracker, $updated);

        self::assertSame('Renamed event', $tracker->getTournamentName());
        self::assertSame('New lane name', $tracker->blade(PerformanceBladeLane::A)->getDisplayName());
        self::assertCount(2, $tracker->getMatches());
        self::assertSame(PerformanceResultValue::PlusTwo, $tracker->match(1)?->resultFor($tracker->blade(PerformanceBladeLane::A))->getValue());
    }

    public function testDuplicateCopiesSetupButNoResults(): void
    {
        $service = $this->trackerService();
        $tracker = $service->create($this->trackerData())->tracker;
        $service->record($tracker, 1, PerformanceBladeLane::A, PerformanceResultValue::PlusThree);

        $copy = $service->duplicate($tracker);

        self::assertNotSame($tracker->getId(), $copy->tracker->getId());
        self::assertNotSame($tracker->getShareId(), $copy->tracker->getShareId());
        self::assertSame($tracker->getTournamentName(), $copy->tracker->getTournamentName());
        self::assertCount(1, $copy->tracker->getMatches());
        self::assertSame(PerformanceResultValue::NotRecorded, $copy->tracker->match(1)?->resultFor($copy->tracker->blade(PerformanceBladeLane::A))->getValue());
    }

    public function testIncreasingTheExpectedCountAfterFinishingOpensANewMatch(): void
    {
        $service = $this->trackerService();
        $tracker = $service->create($this->trackerData(expectedMatches: 1))->tracker;
        $service->record($tracker, 1, PerformanceBladeLane::A, PerformanceResultValue::PlusOne);
        $service->record($tracker, 1, PerformanceBladeLane::B, PerformanceResultValue::Unused);
        $service->record($tracker, 1, PerformanceBladeLane::C, PerformanceResultValue::Zero);
        self::assertNull($tracker->match(2));

        $service->update($tracker, $this->trackerData(expectedMatches: 2));

        self::assertNotNull($tracker->match(2));
        self::assertFalse($tracker->match(2)->isComplete());
    }

    private function trackerService(): PerformanceTrackerService
    {
        return $this->service(PerformanceTrackerService::class);
    }

    private function presenter(): PerformanceTrackerPresenter
    {
        return $this->service(PerformanceTrackerPresenter::class);
    }

    private function trackerData(int $expectedMatches = 5): PerformanceTrackerData
    {
        return new PerformanceTrackerData(
            'Gamesplus 16-08',
            new \DateTimeImmutable('2026-08-16'),
            'Derius',
            'Gamesplus',
            $expectedMatches,
            [
                new PerformanceBladeData('Phoenix Wing', 'Phoenix Wing', '5-60', 'Point', 'cyan'),
                new PerformanceBladeData('Wizard Rod', 'Wizard Rod', '9-60', 'Ball', 'amber'),
                new PerformanceBladeData('Shark Edge', 'Shark Edge', '3-60', 'Low Flat', 'red'),
            ],
        );
    }
}
