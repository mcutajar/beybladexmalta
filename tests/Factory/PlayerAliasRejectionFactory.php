<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\PlayerAliasRejection;
use App\Service\AliasNormaliser;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/** @extends PersistentObjectFactory<PlayerAliasRejection> */
final class PlayerAliasRejectionFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return PlayerAliasRejection::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return ['player' => PlayerFactory::new(), 'spelling' => self::faker()->userName()];
    }

    #[\Override]
    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $parameters): array {
            $parameters['normalised'] = (new AliasNormaliser())->normalise((string) $parameters['spelling']);

            return $parameters;
        });
    }
}
