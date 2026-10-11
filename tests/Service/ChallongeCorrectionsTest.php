<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeStanding;
use App\Service\ChallongeCorrections;
use App\Service\ChallongeSnapshotReader;
use App\Tests\Support\ServiceTestCase;

final class ChallongeCorrectionsTest extends ServiceTestCase
{
    private const string SLUG = 'kxh6q71z';

    private const int MATCH = 475382253;

    public function testItCorrectsTheMatchWithoutChangingTheCapturedSnapshot(): void
    {
        $raw = $this->service(ChallongeSnapshotReader::class)->read(self::SLUG);
        $corrected = $this->service(ChallongeCorrections::class)->apply($raw);

        self::assertSame([[[0, 73]], [0, 73]], [$this->match($raw->stages[0]->matches)->games, $this->match($raw->stages[0]->matches)->score]);
        self::assertSame([[[0, 7]], [0, 7]], [$this->match($corrected->stages[0]->matches)->games, $this->match($corrected->stages[0]->matches)->score]);
    }

    public function testItCorrectsTheDerivedStandingsAndPointTotal(): void
    {
        $raw = $this->service(ChallongeSnapshotReader::class)->read(self::SLUG);
        $corrected = $this->service(ChallongeCorrections::class)->apply($raw);

        self::assertSame(66, $raw->pointsScored() - $corrected->pointsScored());
        self::assertSame('29', $this->standing($corrected->stages[0]->standings, 'Markulegend')->columns['Pts Diff']);
        self::assertSame('-3', $this->standing($corrected->stages[0]->standings, 'Josef')->columns['Pts Diff']);
    }

    public function testABracketWithoutACorrectionIsLeftAlone(): void
    {
        $snapshot = $this->service(ChallongeSnapshotReader::class)->read('wc0vkczl');

        self::assertSame($snapshot, $this->service(ChallongeCorrections::class)->apply($snapshot));
    }

    /**
     * @param list<ChallongeMatch> $matches
     */
    private function match(array $matches): ChallongeMatch
    {
        foreach ($matches as $match) {
            if (self::MATCH === $match->id) {
                return $match;
            }
        }

        self::fail(sprintf('Match %d was not captured.', self::MATCH));
    }

    /**
     * @param list<ChallongeStanding> $standings
     */
    private function standing(array $standings, string $name): ChallongeStanding
    {
        foreach ($standings as $standing) {
            if ($name === $standing->name) {
                return $standing;
            }
        }

        self::fail(sprintf('%s has no standing.', $name));
    }
}
