<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Repository\TournamentRepository;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * The invariant the whole epic turns on, asserted against the database rather
 * than against the entity in isolation.
 *
 * **A tournament scores if and only if it belongs to a season.** Ranked means
 * `season IS NOT NULL` and its points live in `TournamentResult`; unranked
 * means `season IS NULL` and *no* result row exists — not a zero-point one.
 *
 * Nothing writes an unranked event yet; #91 is where the import learns to.
 * What is proved here is that the column accepts one and that `isRanked()` is
 * the question everything else asks.
 */
#[WithStory(SeasonStory::class)]
final class UnrankedTournamentTest extends ServiceTestCase
{
    use ResetDatabase;

    public function testAnEventInASeasonIsRanked(): void
    {
        $event = TournamentFactory::createOne([
            'season' => SeasonStory::freeSeason(),
            'title' => 'Gamesplus 16-08',
        ]);

        self::assertTrue($event->isRanked());
        self::assertSame('free-season', $event->getSeason()?->getSlug());
    }

    /**
     * The column is nullable, and a row written without a season survives the
     * round trip rather than being rejected by a `NOT NULL` the mapping used
     * to declare.
     */
    public function testAnEventWithNoSeasonPersistsAndIsUnranked(): void
    {
        TournamentFactory::createOne([
            'season' => null,
            'title' => 'Malta International Exhibition',
        ]);

        self::getContainer()->get('doctrine')->getManager()->clear();

        $event = $this->service(TournamentRepository::class)->findByTitle('Malta International Exhibition')[0];

        self::assertNull($event->getSeason());
        self::assertFalse($event->isRanked());
        self::assertCount(0, $event->getResults());
    }

    /**
     * Both kinds coexist, and the ranked one is untouched by the presence of
     * the other — which is the whole promise of the gate.
     */
    public function testAnUnrankedEventLeavesARankedOneAlone(): void
    {
        $ranked = TournamentFactory::createOne([
            'season' => SeasonStory::freeSeason(),
            'title' => 'Gamesplus 23-08',
        ]);

        TournamentResultFactory::createOne([
            'tournament' => $ranked,
            'rank' => 1,
            'f1Points' => 25,
            'bonusPoints' => 10,
        ]);

        TournamentFactory::createOne(['season' => null, 'title' => 'Exhibition night']);

        self::assertTrue($ranked->isRanked());
        self::assertCount(1, $ranked->getResults());
        TournamentResultFactory::assert()->count(1);
    }
}
