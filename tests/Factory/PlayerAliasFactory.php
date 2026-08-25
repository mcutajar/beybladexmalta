<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\PlayerAlias;
use App\Entity\PlayerAliasSource;
use App\Service\AliasNormaliser;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<PlayerAlias>
 */
final class PlayerAliasFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return PlayerAlias::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'player' => PlayerFactory::new(),
            'alias' => self::faker()->userName(),
            'source' => PlayerAliasSource::Manual,
        ];
    }

    /**
     * The normalised form is derived rather than passed, so a test can say
     * what a bracket spelled without also having to say what it folds to — and
     * so no fixture can file a spelling under a normalised form that is not
     * its own.
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $parameters): array {
            $parameters['normalised'] = (new AliasNormaliser())->normalise((string) $parameters['alias']);

            return $parameters;
        });
    }
}
