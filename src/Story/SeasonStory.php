<?php

declare(strict_types=1);

namespace App\Story;

use App\Entity\Season;
use App\Factory\SeasonFactory;
use Zenstruck\Foundry\Attribute\AsFixture;
use Zenstruck\Foundry\Story;

/**
 * @method static Season paymentSeason()
 * @method static Season freeSeason()
 */
#[AsFixture(name: 'payment-seasons')]
final class SeasonStory extends Story
{
    public function build(): void
    {
        $this->addState(
            'paymentSeason',
            SeasonFactory::createOne([
                'name' => 'Paid Season',
                'requiresPayment' => true,
                'slug' => 'paid-season',
            ])
        );

        $this->addState(
            'freeSeason',
            SeasonFactory::createOne([
                'name' => 'Free Season',
                'requiresPayment' => false,
                'slug' => 'free-season',
            ])
        );
    }
}
