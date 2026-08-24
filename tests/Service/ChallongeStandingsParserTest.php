<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ChallongeFetchException;
use App\Service\ChallongeStandingsParser;
use PHPUnit\Framework\TestCase;

final class ChallongeStandingsParserTest extends TestCase
{
    private ChallongeStandingsParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new ChallongeStandingsParser();
    }

    public function testItKeepsEveryStatColumnUnderTheLabelChallongePrinted(): void
    {
        $standing = $this->parser->parse($this->swissTable(
            "<td>1</td><td class='participant'>Obelix</td><td>5 - 0 - 0</td><td>0</td><td>5.0</td><td>8.0</td><td>0</td><td>29</td><td class='match-history'></td>",
        ))[0];

        self::assertSame(1, $standing->rank);
        self::assertSame('Obelix', $standing->name);
        self::assertSame([
            'Match W-L-T (wins +1.0, ties +0.5)' => '5 - 0 - 0',
            'Byes (+1.0)' => '0',
            'Score' => '5.0',
            'Buchholz' => '8.0',
            'TB' => '0',
            'Pts Diff' => '29',
        ], $standing->columns);
    }

    /**
     * The `Byes` column exists only in the brackets that had byes, which is
     * why nothing here may count columns by position.
     */
    public function testItReadsATableWithoutTheByesColumn(): void
    {
        $html = '<table><thead><tr><th>Rank</th><th>Participant</th><th>Score</th></tr></thead>'
            ."<tbody><tr><td>2</td><td class='participant'>Obelix</td><td>4.0</td></tr></tbody></table>";

        $standing = $this->parser->parse($html)[0];

        self::assertSame(['Score' => '4.0'], $standing->columns);
    }

    public function testItCollectsTheMatchesEachRowLinksTo(): void
    {
        $standing = $this->parser->parse($this->swissTable(
            '<td>1</td><td class=\'participant\'>Obelix</td><td></td><td></td><td></td><td></td><td></td><td></td>'
            .'<td class=\'match-history\'><a data-match-id="463345513"><div>W</div></a><a data-match-id="463347642"><div>L</div></a></td>',
        ))[0];

        self::assertSame([463345513, 463347642], $standing->matchIds);
    }

    public function testItLiftsTheBadgeOffTheParticipantsName(): void
    {
        $standing = $this->parser->parse($this->swissTable(
            "<td>1</td><td class='participant'><span class='label label-info'>Advanced</span> Obelix</td>",
        ))[0];

        self::assertSame(['Advanced'], $standing->labels);
        self::assertSame('Obelix', $standing->name);
    }

    /**
     * A blader who linked their Challonge account is rendered as that account
     * — in a Swiss table, in place of their display name entirely. Reporting
     * the name as "Sanya0207" would be a lie; this is why #48 joins rows to
     * participants by match id rather than by name.
     */
    public function testItSeparatesALinkedAccountFromTheName(): void
    {
        $standing = $this->parser->parse($this->swissTable(
            '<td>1</td><td class=\'participant\'><a href="https://challonge.com/users/sanya0207">Sanya0207</a></td>',
        ))[0];

        self::assertNull($standing->name);
        self::assertSame('Sanya0207', $standing->challongeUser);
    }

    public function testItReadsTheChallongeUserColumnOfAFinalStageTable(): void
    {
        [$linked, $unlinked] = $this->parser->parse(
            '<table><thead><tr><th>Rank</th><th>Participant Name</th><th>Challonge User</th></tr></thead><tbody>'
            .'<tr><td>1</td><td><strong>Evilbeys</strong></td><td><a href="https://challonge.com/users/evilbeys">Evilbeys</a></td></tr>'
            .'<tr><td>2</td><td><strong>Kane</strong></td><td>&ndash;</td></tr>'
            .'</tbody></table>',
        );

        self::assertSame('Evilbeys', $linked->name);
        self::assertSame('Evilbeys', $linked->challongeUser);

        self::assertSame('Kane', $unlinked->name);
        self::assertNull($unlinked->challongeUser);
    }

    public function testItKeepsTiedRanksAsChallongeGaveThem(): void
    {
        $standings = $this->parser->parse(
            '<table><thead><tr><th>Rank</th><th>Participant</th></tr></thead><tbody>'
            .'<tr><td>5</td><td>Danjel</td></tr><tr><td>5</td><td>Myers</td></tr></tbody></table>',
        );

        self::assertSame([5, 5], array_map(static fn ($standing): int => $standing->rank, $standings));
    }

    public function testABracketWithNoStandingsTableHasNoRows(): void
    {
        self::assertSame([], $this->parser->parse(null));
        self::assertSame([], $this->parser->parse('   '));
        self::assertSame([], $this->parser->parse('<div id="scorecard"><p>Nothing yet.</p></div>'));
    }

    public function testItSaysWhichColumnsItFoundWhenTheTableIsNotAStandingsTable(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('The standings table has no "Rank" column. Its columns are: "Player", "Points".');

        $this->parser->parse('<table><thead><tr><th>Player</th><th>Points</th></tr></thead><tbody><tr><td>Obelix</td><td>5</td></tr></tbody></table>');
    }

    public function testItRefusesARankItCannotRead(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('"tied" is not a rank.');

        $this->parser->parse('<table><thead><tr><th>Rank</th><th>Participant</th></tr></thead><tbody><tr><td>tied</td><td>Obelix</td></tr></tbody></table>');
    }

    /**
     * The Swiss header, with the line breaks Challonge puts inside it.
     */
    private function swissTable(string $cells): string
    {
        return "<div class='standings-container'><table><thead><tr>"
            .'<th>Rank</th><th>Participant</th><th>Match W-L-T<br />(wins +1.0, ties +0.5)</th>'
            .'<th>Byes<br />(+1.0)</th><th>Score</th><th>Buchholz</th><th>TB</th><th>Pts Diff</th>'
            .'<th>Match History</th>'
            ."</tr></thead><tbody><tr>{$cells}</tr></tbody></table></div>";
    }
}
