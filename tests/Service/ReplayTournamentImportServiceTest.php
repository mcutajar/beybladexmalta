<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\TournamentPlacement;
use App\Exception\LedgerWriteException;
use App\Repository\TournamentStageRepository;
use App\Service\ChallongeSnapshotFiles;
use App\Service\ChallongeSnapshotReader;
use App\Service\ReplayTournamentImportService;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ReplayTournamentImportServiceTest extends ServiceTestCase
{
    public function testLedgerFailureRollsBackScoringAndArchiveTogether(): void
    {
        $slug = 'co5nncw8';
        $snapshot = $this->service(ChallongeSnapshotReader::class)->read($slug);
        $snapshotPath = $this->service(ChallongeSnapshotFiles::class)->pathFor($slug);

        self::blockLedgerWrites();

        try {
            $this->service(ReplayTournamentImportService::class)->import(
                title: 'Replay transaction test',
                heldOn: '2026-08-27',
                seasonSlug: 'paid-season',
                placements: [new TournamentPlacement('Giglio', 0)],
                sourceFilePath: '/app/var/data/imports/replay-transaction-test.txt',
                snapshot: $snapshot,
                snapshotPath: $snapshotPath,
                challongeUrl: $snapshot->sourceUrl,
            );

            self::fail('The blocked ledger write should have aborted the replay.');
        } catch (LedgerWriteException) {
            TournamentFactory::assert()->empty();
            TournamentResultFactory::assert()->empty();
            self::assertSame(0, $this->service(TournamentStageRepository::class)->count([]));
        }
    }
}
