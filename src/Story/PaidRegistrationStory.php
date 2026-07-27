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
 * @method static Player bob()
 * @method static SeasonRegistration registration()
 */
final class PaidRegistrationStory extends Story
{
    public function build(): void
    {
        $season = SeasonStory::paymentSeason();

        $bob = PlayerFactory::createOne([
            'name' => 'Bob',
        ]);

        $registration = SeasonRegistrationFactory::createOne([
            'player' => $bob,
            'season' => $season,
            'paid' => true,
        ]);

        $this->addState('bob', $bob);
        $this->addState('registration', $registration);
    }
}