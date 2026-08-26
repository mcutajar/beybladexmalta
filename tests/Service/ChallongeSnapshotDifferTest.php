<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeStanding;
use App\Dto\SnapshotDifference;
use App\Service\ChallongeSnapshotDiffer;
use PHPUnit\Framework\TestCase;

final class ChallongeSnapshotDifferTest extends TestCase
{
    private ChallongeSnapshotDiffer $differ;

    protected function setUp(): void
    {
        parent::setUp();

        $this->differ = new ChallongeSnapshotDiffer();
    }

    /**
     * The reason this class exists rather than `git diff`. Every fetch stamps
     * a new `fetched_at`, so a re-fetch of a bracket nobody has touched
     * produces one line of diff — and a tool whose quiet answer looks like a
     * change is not an answer.
     */
    public function testAFreshFetchOfAnUnchangedBracketHasNothingToSay(): void
    {
        self::assertSame([], $this->differ->compare(
            $this->snapshot(),
            $this->snapshot(fetchedAt: '2027-01-01T09:30:00+00:00'),
        ));
    }

    public function testItNamesTheFieldAScoreWasCorrectedIn(): void
    {
        $differences = $this->differ->compare(
            $this->snapshot(),
            $this->snapshot(score: [7, 5]),
        );

        self::assertSame(
            [
                'stages[0].matches[0].games[0][1]: 4 is now 5.',
                'stages[0].matches[0].score[1]: 4 is now 5.',
            ],
            $this->described($differences),
        );
        self::assertSame('4', $differences[1]->stored);
        self::assertSame('5', $differences[1]->fetched);
    }

    public function testItNamesTheEntrantARenameChanged(): void
    {
        $differences = $this->differ->compare(
            $this->snapshot(),
            $this->snapshot(entrant: 'Obelisk'),
        );

        self::assertSame(
            ['stages[0].participants[1].name: "Obelix" is now "Obelisk".'],
            $this->described($differences),
        );
    }

    /**
     * A match added upstream is one line, and it says which side has it.
     */
    public function testItReportsAMatchTheBracketHasGained(): void
    {
        $differences = $this->differ->compare(
            $this->snapshot(),
            $this->snapshot(matches: 2),
        );

        self::assertCount(1, $differences);
        self::assertSame('stages[0].matches[1]', $differences[0]->path);
        self::assertNull($differences[0]->stored);
        self::assertStringStartsWith('stages[0].matches[1]: the bracket has gained {"id":902', $differences[0]->describe());
    }

    public function testItReportsAMatchTheBracketNoLongerHas(): void
    {
        $differences = $this->differ->compare(
            $this->snapshot(matches: 2),
            $this->snapshot(),
        );

        self::assertCount(1, $differences);
        self::assertNull($differences[0]->fetched);
        self::assertStringStartsWith('stages[0].matches[1]: the bracket has dropped', $differences[0]->describe());
    }

    /**
     * A subtree that exists on one side only is reported where it appears
     * rather than descended into: a stage the bracket has gained is one line,
     * not four hundred.
     */
    public function testAWholeStageIsOneLine(): void
    {
        $differences = $this->differ->compare(
            $this->snapshot(),
            $this->snapshot(cut: true),
        );

        self::assertCount(1, $differences);
        self::assertSame('stages[1]', $differences[0]->path);
    }

    /**
     * A match Challonge never labelled carries a null identifier, and one it
     * has since labelled changed a value rather than gained a field. The two
     * read differently on purpose.
     */
    public function testANullValueIsNotAMissingField(): void
    {
        $differences = $this->differ->compare(
            $this->snapshot(identifier: null),
            $this->snapshot(identifier: 'A'),
        );

        self::assertSame(
            ['stages[0].matches[0].identifier: null is now "A".'],
            $this->described($differences),
        );
    }

    /**
     * @param list<SnapshotDifference> $differences
     *
     * @return list<string>
     */
    private function described(array $differences): array
    {
        return array_map(
            static fn (SnapshotDifference $difference): string => $difference->describe(),
            $differences,
        );
    }

    /**
     * @param list<int> $score
     */
    private function snapshot(
        string $fetchedAt = '2026-08-24T12:00:00+00:00',
        array $score = [7, 4],
        string $entrant = 'Obelix',
        int $matches = 1,
        bool $cut = false,
        ?string $identifier = 'A',
    ): ChallongeSnapshot {
        $stages = [
            new ChallongeStage(
                kind: ChallongeStageKind::Group,
                name: 'Group A',
                format: 'swiss',
                rounds: [],
                participants: [
                    new ChallongeParticipant(id: 1, participantId: null, seed: 1, name: 'legion'),
                    new ChallongeParticipant(id: 2, participantId: null, seed: 2, name: $entrant),
                ],
                matches: array_map(
                    fn (int $offset): ChallongeMatch => new ChallongeMatch(
                        id: 901 + $offset,
                        round: 1 + $offset,
                        identifier: $identifier,
                        state: 'complete',
                        player1Id: 1,
                        player2Id: 2,
                        games: [$score],
                        score: $score,
                        winnerId: 1,
                        loserId: 2,
                        forfeited: false,
                        consolation: false,
                    ),
                    range(0, $matches - 1),
                ),
                standings: [
                    new ChallongeStanding(rank: 1, name: 'legion', challongeUser: null, labels: [], matchIds: [901], columns: []),
                ],
            ),
        ];

        if ($cut) {
            $stages[] = new ChallongeStage(
                kind: ChallongeStageKind::Final,
                name: null,
                format: 'single elimination',
                rounds: [],
                participants: [],
                matches: [],
                standings: [],
            );
        }

        return new ChallongeSnapshot(
            slug: 'co5nncw8',
            sourceUrl: 'https://challonge.com/co5nncw8/module?show_standings=1',
            fetchedAt: new \DateTimeImmutable($fetchedAt),
            tournamentId: 18113372,
            tournamentType: 'single elimination',
            tournamentState: 'complete',
            isTeamTournament: false,
            stages: $stages,
        );
    }
}
