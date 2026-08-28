<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Factory\PlayerFactory;
use App\Tests\Factory\SeasonFactory;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentResultFactory;
use App\Tests\Story\PaidRegistrationStory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\PageTestCase;
use Zenstruck\Foundry\Attribute\WithStory;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * Smoke tests over the templates.
 *
 * Every page shares one layout and one set of components now, so a mistake in
 * a component is a mistake on several pages at once. These assert only that
 * each page renders — what it says is the other tests' business.
 */
final class PageRendersTest extends PageTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * The styleguide exercises every component in every variant, which makes
     * it the canary for the whole design system.
     */
    public function testTheStyleguideRenders(): void
    {
        $this->assertPageRenders('/_styleguide');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('landingPages')]
    public function testALandingPageRenders(string $path): void
    {
        $this->assertPageRenders($path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function landingPages(): iterable
    {
        yield 'season 1 framework' => ['/'];
        yield 'proposal v1' => ['/v1'];
        yield 'proposal v0' => ['/v0'];
    }

    public function testTheRegistrationsPageRendersWithNothingToShow(): void
    {
        $this->assertPageRenders('/registrations');
    }

    #[WithStory(PaidRegistrationStory::class)]
    public function testTheRegistrationsPageRendersAPaidBlader(): void
    {
        $this->assertPageRenders('/registrations');
    }

    #[WithStory(SeasonStory::class)]
    public function testTheLeaderboardRenders(): void
    {
        $this->assertPageRenders('/season/paid-season');
    }

    #[WithStory(PaidRegistrationStory::class)]
    public function testAPlayerPageRenders(): void
    {
        $player = PaidRegistrationStory::bob();

        $this->assertPageRenders(sprintf('/season/paid-season/player/%d', $player->getId()));
    }

    #[WithStory(SeasonStory::class)]
    public function testATournamentPageRenders(): void
    {
        $tournament = TournamentFactory::createOne([
            'season' => SeasonStory::paymentSeason(),
            'title' => 'Gamesplus 08-02',
        ]);

        TournamentResultFactory::createOne([
            'bonusPoints' => 10,
            'f1Points' => 25,
            'player' => PlayerFactory::createOne(['name' => 'Derius']),
            'rank' => 1,
            'tournament' => $tournament,
        ]);

        $this->assertPageRenders(sprintf('/season/paid-season/tournament/%d', $tournament->getId()));
    }

    /**
     * `/preseason` and `/seasons/{slug}` are legacy aliases onto the same
     * controllers. They are public URLs people have bookmarked, so they render
     * through the same layout and are worth the two extra requests.
     */
    public function testTheLegacySeasonAliasesRender(): void
    {
        SeasonFactory::createOne(['name' => 'Preseason 1', 'slug' => 'preseason-1']);

        $this->assertPageRenders('/preseason');
        $this->assertPageRenders('/seasons/preseason-1');
    }

    #[WithStory(SeasonStory::class)]
    public function testTheAdminPagesRender(): void
    {
        $this->assertPageRenders('/admin/payments');
        $this->assertPageRenders('/admin/import');
        $this->assertPageRenders('/admin/merge-player');
    }
}
