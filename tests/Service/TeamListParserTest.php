<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\TeamPlacement;
use App\Service\TeamListParser;
use PHPUnit\Framework\TestCase;

final class TeamListParserTest extends TestCase
{
    private TeamListParser $parser;

    public function testItReadsATeamAndTheBladersInIt(): void
    {
        $teams = $this->parser->parse("irmied u gebel: Butcher + Obelix\n");

        self::assertCount(1, $teams);
        self::assertSame('irmied u gebel', $teams[0]->teamName);
        self::assertSame(['Butcher', 'Obelix'], $teams[0]->memberNames);
        self::assertSame(1, $teams[0]->rank);
        self::assertFalse($teams[0]->isUnclaimed());
    }

    public function testItRanksTeamsByTheOrderTheyWereTypedIn(): void
    {
        $teams = $this->parser->parse("first: A + B\nsecond: C + D\nthird: E + F\n");

        self::assertSame([1, 2, 3], array_map(
            static fn (TeamPlacement $team): int => $team->rank,
            $teams,
        ));
    }

    /**
     * The whole reason unclaimed had to become a record: `JG` finished tenth
     * and nobody knows who was in it, and the alternative to writing the row
     * is losing the placing.
     */
    public function testATeamWithNobodyAfterTheColonIsUnclaimed(): void
    {
        $teams = $this->parser->parse("JG:\n");

        self::assertSame('JG', $teams[0]->teamName);
        self::assertSame([], $teams[0]->memberNames);
        self::assertTrue($teams[0]->isUnclaimed());
    }

    /**
     * `bye` is written the way the bracket wrote it, with no colon at all. The
     * parser has no opinion about it — dropping it is the import's rule, and
     * this only has to not choke on the line.
     */
    public function testALineWithNoColonIsATeamWithNobodyInIt(): void
    {
        $teams = $this->parser->parse("mafia: Otrebor + Federico\nbye\n");

        self::assertSame('bye', $teams[1]->teamName);
        self::assertTrue($teams[1]->isUnclaimed());
        self::assertSame(2, $teams[1]->rank);
    }

    public function testItIgnoresBlankLinesWithoutSpendingARankOnThem(): void
    {
        $teams = $this->parser->parse("first: A + B\n\n   \nsecond: C + D\n");

        self::assertCount(2, $teams);
        self::assertSame(2, $teams[1]->rank);
    }

    public function testItTrimsAroundTheNameAndEveryBlader(): void
    {
        $teams = $this->parser->parse("  the bakers  :  Belti  +  Amanda  \n");

        self::assertSame('the bakers', $teams[0]->teamName);
        self::assertSame(['Belti', 'Amanda'], $teams[0]->memberNames);
    }

    public function testAnEmptyFileHoldsNoTeams(): void
    {
        self::assertSame([], $this->parser->parse("\n \n"));
    }

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new TeamListParser();
    }
}
