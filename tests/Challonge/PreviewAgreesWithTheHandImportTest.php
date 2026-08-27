<?php

declare(strict_types=1);

namespace App\Tests\Challonge;

use App\Dto\BracketPlacement;
use App\Service\BracketPreviewer;
use App\Service\ChallongeSnapshotReader;
use App\Tests\Factory\PlayerAliasFactory;
use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;

/**
 * The argument for importing from a bracket at all, put to one real evening.
 *
 * `CapturedBracketsTest` proves that rank *n* of every captured bracket is line
 * *n* of the placement list somebody typed at the time. This proves the rest of
 * the claim: that the preview screen, with the alias table the league actually
 * has, arrives at the same ten **bladers** in the same order — and names the
 * same knockout winner that `repeat.sh` records.
 *
 * Both sides are tracked files, so nothing here is fixture data invented to
 * agree with itself: `var/data/challonge/nppk0890.json` is what Challonge
 * served, and `var/data/imports/2026-08-16-gamesplus-16-08.txt` is what
 * somebody typed on the night.
 */
#[ResetDatabase]
final class PreviewAgreesWithTheHandImportTest extends ServiceTestCase
{
    private const SLUG = 'nppk0890';

    private const PLACEMENT_FILE = '2026-08-16-gamesplus-16-08.txt';

    /**
     * As recorded on the import line in `repeat.sh`.
     */
    private const KNOCKOUT_WINNER = 'Guzman93';

    /**
     * The two spellings in this bracket's top ten that a stored alias settles.
     * Everything else folds on its own — `MarkuLegend`, `Rip_N_Burst` and
     * `Fir3BladeTv` differ from the league's spelling only in case and
     * punctuation, which `AliasNormaliser` removes.
     *
     * @var array<string, string> the bracket's spelling => the blader
     */
    private const ALIASES = [
        'Guzman' => 'Guzman93',
        'Anzjan' => 'Lanzjan',
    ];

    public function testThePreviewScoresTheSameTenBladersInTheSameOrder(): void
    {
        $expected = $this->handImportedOrder();

        $this->league($expected);

        $preview = $this->service(BracketPreviewer::class)->preview(
            $this->service(ChallongeSnapshotReader::class)->read(self::SLUG),
            'https://challonge.com/'.self::SLUG,
            'Gamesplus 16-08',
            '2026-08-16',
            'a-season',
        );

        self::assertTrue($preview->isReady(), $preview->refusal());

        self::assertSame(
            $expected,
            array_map(
                static fn (BracketPlacement $placement): ?string => $placement->bladerName,
                $preview->scoring(),
            ),
        );
    }

    public function testTheKnockoutWinnerIsTheOneTheImportWasToldAboutByHand(): void
    {
        $this->league($this->handImportedOrder());

        $preview = $this->service(BracketPreviewer::class)->preview(
            $this->service(ChallongeSnapshotReader::class)->read(self::SLUG),
            'https://challonge.com/'.self::SLUG,
            'Gamesplus 16-08',
            '2026-08-16',
            'a-season',
        );

        $winner = $preview->knockoutWinner();

        self::assertNotNull($winner, 'The bracket names a winner of the cut.');
        self::assertSame(self::KNOCKOUT_WINNER, $winner->bladerName);
        self::assertSame(10, $winner->bonusPoints);
    }

    /**
     * The ten bladers, and the two aliases that make the bracket readable.
     *
     * A season too, because the preview names the import file it would write
     * and the file is named after the event rather than the season — but the
     * refusal for a missing season belongs to the import, and this test is
     * about the order.
     *
     * @param list<string> $bladers
     */
    private function league(array $bladers): void
    {
        SeasonFactory::createOne(['slug' => 'a-season']);

        foreach ($bladers as $name) {
            PlayerFactory::createOne(['name' => $name]);
        }

        foreach (self::ALIASES as $spelling => $blader) {
            PlayerAliasFactory::createOne([
                'player' => PlayerFactory::find(['name' => $blader]),
                'alias' => $spelling,
            ]);
        }
    }

    /**
     * @return list<string> the placement list as it was typed on the night
     */
    private function handImportedOrder(): array
    {
        $path = sprintf('%s/var/data/imports/%s', self::projectDir(), self::PLACEMENT_FILE);

        self::assertFileExists($path);

        return array_values(array_filter(
            array_map(trim(...), explode("\n", (string) file_get_contents($path))),
            static fn (string $line): bool => '' !== $line,
        ));
    }
}
