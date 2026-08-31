<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeRecord;
use App\Dto\ChallongeStanding;
use App\Service\ChallongeRecordReader;
use PHPUnit\Framework\TestCase;

/**
 * The one place a standings column stops being a string.
 *
 * The labels here are the ones the eighteen captured brackets actually print,
 * parentheticals and all, because that is the whole difficulty: Challonge
 * writes the scoring rule into the header, so the same column is `Match W-L-T`
 * in the league's one round robin and `Match W-L-T (wins +1.0, ties +0.5)`
 * everywhere else.
 */
final class ChallongeRecordReaderTest extends TestCase
{
    private ChallongeRecordReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = new ChallongeRecordReader();
    }

    public function testItReadsASwissRow(): void
    {
        $record = $this->reader->read($this->standing([
            'Match W-L-T (wins +1.0, ties +0.5)' => '4 - 1 - 0',
            'Score' => '4.0',
            'Buchholz' => '10.0',
            'TB' => '1',
            'Pts' => '89',
            'Pts Diff' => '+17',
        ]));

        self::assertSame([4, 1, 0], [$record->wins, $record->losses, $record->ties]);
        self::assertSame(4.0, $record->score);
        self::assertSame(10.0, $record->buchholz);
        self::assertSame(1.0, $record->tieBreak);
        self::assertSame(89, $record->points);
        self::assertSame(17, $record->pointsDifferential);
    }

    /**
     * A tie is worth half a win, so the score column is not an integer and
     * cannot be stored as one.
     */
    public function testItKeepsTheHalfPointATieIsWorth(): void
    {
        self::assertSame(1.5, $this->reader->read($this->standing(['Score' => '1.5']))->score);
    }

    /**
     * A negative differential is as ordinary as a positive one, and Challonge
     * writes the sign either way.
     */
    public function testItReadsASignedDifferentialEitherWay(): void
    {
        self::assertSame(-4, $this->reader->read($this->standing(['Pts Diff' => '-4']))->pointsDifferential);
        self::assertSame(29, $this->reader->read($this->standing(['Pts Diff' => '+29']))->pointsDifferential);
    }

    public function testItReadsTheSameColumnWhicheverWayItIsLabelled(): void
    {
        self::assertSame(5, $this->reader->read($this->standing(['Match W-L-T' => '5 - 1 - 0']))->wins);
        self::assertSame(1, $this->reader->read($this->standing(['Byes (+1.0)' => '1']))->byes);
    }

    /**
     * The `Byes` column exists only in the brackets that had byes — eleven of
     * the thirty-three standings tables — so its absence has to read as
     * absent. A zero would say the bracket counted them and found none.
     */
    public function testAColumnThatIsNotThereIsNotZero(): void
    {
        $record = $this->reader->read($this->standing(['Score' => '4.0']));

        self::assertNull($record->byes);
        self::assertNull($record->buchholz);
        self::assertNull($record->wins);
    }

    /**
     * The standings of a cut are eight rows of a rank and a match history.
     */
    public function testARowWithNoColumnsSaysNothing(): void
    {
        $record = $this->reader->read($this->standing([]));

        self::assertEquals(ChallongeRecord::nothing(), $record);
    }

    /**
     * A cell that is not a number reads as absent rather than as zero: the
     * columns are free-form by design, and inventing a value is the one thing
     * this side of the pipeline must not do.
     */
    public function testACellItCannotReadIsNotAValue(): void
    {
        $record = $this->reader->read($this->standing([
            'Score' => '—',
            'Match W-L-T' => 'W-L',
            'Byes' => '',
        ]));

        self::assertNull($record->score);
        self::assertNull($record->wins);
        self::assertNull($record->byes);
    }

    /**
     * The round robin's `Pts` is a total of Beyblade points and the Swiss
     * `Score` counts match wins. They are not the same number and neither is
     * read as the other.
     */
    public function testItDoesNotReadTheRoundRobinsPointsAsAScore(): void
    {
        $record = $this->reader->read($this->standing(['Pts' => '89', 'Set Wins' => '11']));

        self::assertSame(89, $record->points);
        self::assertNull($record->score);
    }

    /**
     * @param array<string, string> $columns
     */
    private function standing(array $columns): ChallongeStanding
    {
        return new ChallongeStanding(
            rank: 1,
            name: 'Belti',
            challongeUser: null,
            labels: [],
            matchIds: [],
            columns: $columns,
        );
    }
}
