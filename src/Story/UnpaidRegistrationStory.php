<?php

declare(strict_types=1);

namespace App\Tests\Story;

use App\Entity\Player;
use App\Entity\SeasonRegistration;
use App\Factory\PlayerFactory;
use App\Factory\SeasonRegistrationFactory;
use App\Story\SeasonStory;
use Zenstruck\Foundry\Story;

/**
 * @method static Player alice()
 * @method static SeasonRegistration registration()
 */
final class UnpaidRegistrationStory extends Story
{
    public function build(): void
    {
        /*
         * Accessing this state loads SeasonStory if it has not already
         * been loaded.
         */
        $season = SeasonStory::paymentSeason();

        $alice = PlayerFactory::createOne([
            'name' => 'Alice',
        ]);

        $registration = SeasonRegistrationFactory::createOne([
            'player' => $alice,
            'season' => $season,
            'paid' => false,
        ]);

        $this->addState('alice', $alice);
        $this->addState('registration', $registration);
    }
}