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
use App\Exception\ChallongeSnapshotReadException;
use App\Service\ChallongeSnapshotFiles;
use App\Service\ChallongeSnapshotReader;
use App\Service\ChallongeSnapshotWriter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

final class ChallongeSnapshotReaderTest extends TestCase
{
    private const SLUG = '9yuqg2pi';

    private string $projectDir;

    private ChallongeSnapshotFiles $files;

    private ChallongeSnapshotWriter $writer;

    private ChallongeSnapshotReader $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = sys_get_temp_dir().'/challonge-snapshot-'.bin2hex(random_bytes(6));

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir);

        $this->files = new ChallongeSnapshotFiles($kernel);

        $this->writer = new ChallongeSnapshotWriter($this->files);
        $this->reader = new ChallongeSnapshotReader($this->files);
    }

    protected function tearDown(): void
    {
        $this->discard($this->projectDir);

        parent::tearDown();
    }

    /**
     * The two halves have to agree exactly, because the snapshot is the record:
     * anything the reader cannot give back is a fact the fetch captured and
     * nothing can ever use.
     */
    public function testItGivesBackEverythingTheWriterWrote(): void
    {
        $snapshot = $this->snapshot();

        $this->writer->write($snapshot);

        self::assertEquals($snapshot->toArray(), $this->reader->read(self::SLUG)->toArray());
    }

    public function testItReadsTheStagesBackAsObjects(): void
    {
        $this->writer->write($this->snapshot());

        $snapshot = $this->reader->read(self::SLUG);

        self::assertSame('swiss', $snapshot->tournamentType);
        self::assertSame('2026-08-24T12:00:00+00:00', $snapshot->fetchedAt->format(\DATE_ATOM));
        self::assertCount(1, $snapshot->stages);
        self::assertSame(ChallongeStageKind::Single, $snapshot->stages[0]->kind);
        self::assertSame([[7, 4]], $snapshot->stages[0]->matches[0]->games);
        self::assertSame(['Score' => '1.0'], $snapshot->stages[0]->standings[0]->columns);
        self::assertSame([701], $snapshot->stages[0]->standings[0]->matchIds);
    }

    public function testItSaysWhichBracketHasNotBeenCaptured(): void
    {
        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('There is no snapshot at');

        $this->reader->read('nosuchbracket');
    }

    /**
     * A file written by a later version of the app may say things this one
     * would read wrongly, so the version is checked before anything else is
     * touched.
     */
    public function testItRefusesAVersionItDoesNotRead(): void
    {
        $this->save(['version' => ChallongeSnapshot::VERSION + 1] + $this->asArray());

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage(sprintf('is version %d, and this application reads version %d', ChallongeSnapshot::VERSION + 1, ChallongeSnapshot::VERSION));

        $this->reader->read(self::SLUG);
    }

    /**
     * The same rule the fetch applies to Challonge, applied to ourselves: a
     * field that is present and the wrong type means the file is not what it
     * claims to be, and reading round it would invent history.
     */
    public function testItRefusesAFieldThatHasChangedType(): void
    {
        $snapshot = $this->asArray();
        $snapshot['stages'][0]['matches'][0]['games'] = [['7', '4']];

        $this->save($snapshot);

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('The snapshot field "games" holds string where a list of whole numbers was expected.');

        $this->reader->read(self::SLUG);
    }

    public function testItRefusesASnapshotMissingAFieldItNeeds(): void
    {
        $snapshot = $this->asArray();
        unset($snapshot['stages'][0]['matches'][0]['id']);

        $this->save($snapshot);

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('The snapshot field "id" is missing, where a whole number was expected.');

        $this->reader->read(self::SLUG);
    }

    /**
     * A capture always produced at least one stage, so a file with none has
     * been truncated or edited — and an absent list would otherwise read back
     * as a perfectly valid tournament nobody entered.
     */
    public function testItRefusesASnapshotWithNoStages(): void
    {
        $snapshot = $this->asArray();
        $snapshot['stages'] = [];

        $this->save($snapshot);

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('The snapshot field "stages" is missing, where at least one stage was expected.');

        $this->reader->read(self::SLUG);
    }

    public function testItRefusesAKindOfStageItHasNeverHeardOf(): void
    {
        $snapshot = $this->asArray();
        $snapshot['stages'][0]['kind'] = 'quarterfinals';

        $this->save($snapshot);

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('"quarterfinals" is not a kind of stage. The kinds are: group, final, single.');

        $this->reader->read(self::SLUG);
    }

    public function testItRefusesATimestampItCannotRead(): void
    {
        $snapshot = $this->asArray();
        $snapshot['fetched_at'] = 'last Tuesday';

        $this->save($snapshot);

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('"last Tuesday" is not a moment in time.');

        $this->reader->read(self::SLUG);
    }

    /**
     * The awkward half of the same rule. A date of the right shape but not a
     * real one does not fail, it rolls over — PHP reads month 13 day 45 back as
     * February the following year — so parsing successfully proves nothing on
     * its own.
     */
    public function testItRefusesATimestampThatIsNotARealDate(): void
    {
        $snapshot = $this->asArray();
        $snapshot['fetched_at'] = '2026-13-45T99:00:00+00:00';

        $this->save($snapshot);

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('"2026-13-45T99:00:00+00:00" is not a moment in time.');

        $this->reader->read(self::SLUG);
    }

    public function testItRefusesAFileThatIsNotJson(): void
    {
        $this->put('<!doctype html>');

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('is not valid JSON');

        $this->reader->read(self::SLUG);
    }

    public function testItRefusesJsonThatIsNotASnapshot(): void
    {
        $this->put('"a bracket"');

        $this->expectException(ChallongeSnapshotReadException::class);
        $this->expectExceptionMessage('holds string where an object was expected');

        $this->reader->read(self::SLUG);
    }

    /**
     * @return array<string, mixed>
     */
    private function asArray(): array
    {
        return $this->snapshot()->toArray();
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function save(array $snapshot): void
    {
        $this->put(json_encode($snapshot, \JSON_THROW_ON_ERROR));
    }

    private function put(string $contents): void
    {
        $path = $this->files->pathFor(self::SLUG);

        if (!is_dir(dirname($path))) {
            self::assertTrue(mkdir(dirname($path), 0775, true));
        }

        self::assertNotFalse(file_put_contents($path, $contents));
    }

    private function snapshot(): ChallongeSnapshot
    {
        return new ChallongeSnapshot(
            slug: self::SLUG,
            sourceUrl: 'https://challonge.com/9yuqg2pi/module?show_standings=1',
            fetchedAt: new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
            tournamentId: 18169778,
            tournamentType: 'swiss',
            tournamentState: 'complete',
            isTeamTournament: false,
            stages: [new ChallongeStage(
                kind: ChallongeStageKind::Single,
                name: null,
                format: 'swiss',
                rounds: [new ChallongeRound(1, 'Round 1')],
                participants: [
                    new ChallongeParticipant(id: 11, participantId: null, seed: 1, name: 'Obelix'),
                    new ChallongeParticipant(id: 12, participantId: 907, seed: 2, name: 'Giglio'),
                ],
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
                    labels: ['Advanced'],
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
