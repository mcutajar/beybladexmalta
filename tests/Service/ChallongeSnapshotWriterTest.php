<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeRound;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeStanding;
use App\Exception\ChallongeSnapshotWriteException;
use App\Service\ChallongeSnapshotFiles;
use App\Service\ChallongeSnapshotWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ChallongeSnapshotWriterTest extends TestCase
{
    private const SLUG = '9yuqg2pi';

    private string $projectDir;

    private ChallongeSnapshotWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/challonge-snapshot-'.bin2hex(random_bytes(6));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        $this->writer = new ChallongeSnapshotWriter(new ChallongeSnapshotFiles($kernel));
    }

    protected function tearDown(): void
    {
        $this->discard($this->projectDir);

        parent::tearDown();
    }

    public function testItWritesTheSnapshotUnderTheBracketSlug(): void
    {
        $filePath = $this->writer->write($this->snapshot());

        self::assertSame($this->projectDir.'/var/data/challonge/9yuqg2pi.json', $filePath);
        self::assertFileExists($filePath);
    }

    public function testItKeepsEverythingTheFetchCaptured(): void
    {
        $written = json_decode(
            (string) file_get_contents($this->writer->write($this->snapshot())),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertSame([
            'version' => ChallongeSnapshot::VERSION,
            'slug' => self::SLUG,
            'source_url' => 'https://challonge.com/9yuqg2pi/module?show_standings=1',
            'fetched_at' => '2026-08-24T12:00:00+00:00',
            'tournament' => [
                'id' => 18169778,
                'type' => 'swiss',
                'state' => 'complete',
                'is_team' => false,
            ],
            'stages' => [[
                'kind' => 'single',
                'name' => null,
                'format' => 'swiss',
                'rounds' => [['number' => 1, 'title' => 'Round 1']],
                'participants' => [['id' => 11, 'participant_id' => null, 'seed' => 1, 'name' => 'Obelix']],
                'matches' => [[
                    'id' => 701,
                    'round' => 1,
                    'identifier' => 'A',
                    'state' => 'complete',
                    'player1' => 11,
                    'player2' => 12,
                    'games' => [[7, 4]],
                    'score' => [7, 4],
                    'winner' => 11,
                    'loser' => 12,
                    'forfeited' => false,
                    'consolation' => false,
                ]],
                'standings' => [[
                    'rank' => 1,
                    'name' => 'Obelix',
                    'challonge_user' => null,
                    'labels' => [],
                    'match_ids' => [701],
                    'columns' => ['Score' => '1.0'],
                ]],
            ]],
        ], $written);
    }

    /**
     * The snapshot is tracked by git, so it is written to be read: indented,
     * with slashes and accents left alone, and a closing newline.
     */
    public function testItWritesAFileGitCanDiff(): void
    {
        $contents = (string) file_get_contents($this->writer->write($this->snapshot()));

        self::assertStringContainsString("\n    \"slug\": \"9yuqg2pi\",", $contents);
        self::assertStringContainsString('https://challonge.com', $contents);
        self::assertStringEndsWith("}\n", $contents);
    }

    public function testItReplacesAnEarlierCaptureOfTheSameBracket(): void
    {
        $this->writer->write($this->snapshot());
        $filePath = $this->writer->write($this->snapshot(state: 'underway'));

        self::assertStringContainsString('"state": "underway"', (string) file_get_contents($filePath));
        self::assertCount(1, (array) glob(dirname($filePath).'/*'));
    }

    /**
     * A half-written snapshot under the real name is worse than no snapshot,
     * so the file is assembled beside its target and moved into place.
     */
    public function testAFailedWriteLeavesNoFileBehind(): void
    {
        $filePath = $this->writer->pathFor(self::SLUG);

        self::assertTrue(mkdir(dirname($filePath), 0775, true));
        self::assertTrue(mkdir($filePath.'.part'));

        try {
            $this->writer->write($this->snapshot());
            self::fail('Expected the write to fail.');
        } catch (ChallongeSnapshotWriteException $exception) {
            self::assertStringContainsString('Failed to write the snapshot file', $exception->getMessage());
        }

        self::assertFileDoesNotExist($filePath);
    }

    /**
     * The working file is moved onto the target rather than copied, so a
     * successful write leaves nothing beside the snapshot — including anything
     * an earlier crash left there.
     */
    public function testItLeavesNoWorkingFileBesideTheSnapshot(): void
    {
        $filePath = $this->writer->pathFor(self::SLUG);

        self::assertTrue(mkdir(dirname($filePath), 0775, true));
        self::assertNotFalse(file_put_contents($filePath.'.part', 'left over'));

        $this->writer->write($this->snapshot());

        self::assertFileExists($filePath);
        self::assertFileDoesNotExist($filePath.'.part');
    }

    public function testItReportsADirectoryItCannotCreate(): void
    {
        self::assertTrue(mkdir($this->projectDir.'/var/data', 0775, true));
        self::assertNotFalse(file_put_contents($this->projectDir.'/var/data/challonge', 'not a directory'));

        $this->expectException(ChallongeSnapshotWriteException::class);
        $this->expectExceptionMessage('Failed to create the snapshot directory');

        $this->writer->write($this->snapshot());
    }

    private function snapshot(string $state = 'complete'): ChallongeSnapshot
    {
        return new ChallongeSnapshot(
            slug: self::SLUG,
            sourceUrl: 'https://challonge.com/9yuqg2pi/module?show_standings=1',
            fetchedAt: new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
            tournamentId: 18169778,
            tournamentType: 'swiss',
            tournamentState: $state,
            isTeamTournament: false,
            stages: [new ChallongeStage(
                kind: ChallongeStageKind::Single,
                name: null,
                format: 'swiss',
                rounds: [new ChallongeRound(1, 'Round 1')],
                participants: [new ChallongeParticipant(id: 11, participantId: null, seed: 1, name: 'Obelix')],
                matches: [new ChallongeMatch(
                    id: 701,
                    round: 1,
                    identifier: 'A',
                    state: 'complete',
                    player1Id: 11,
                    player2Id: 12,
                    games: [[7, 4]],
                    score: [7, 4],
                    winnerId: 11,
                    loserId: 12,
                    forfeited: false,
                    consolation: false,
                )],
                standings: [new ChallongeStanding(
                    rank: 1,
                    name: 'Obelix',
                    challongeUser: null,
                    labels: [],
                    matchIds: [701],
                    columns: ['Score' => '1.0'],
                )],
            )],
        );
    }

    private function discard(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if (is_string($entry) && !in_array($entry, ['.', '..'], true)) {
                $this->discard($path.'/'.$entry);
            }
        }

        rmdir($path);
    }
}
