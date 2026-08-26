<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\TournamentTeam;
use App\Service\AliasNormaliser;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<TournamentTeam>
 */
final class TournamentTeamFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return TournamentTeam::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'tournament' => TournamentFactory::new(),
            'name' => self::faker()->words(2, true),
            'rank' => self::faker()->numberBetween(1, 12),
        ];
    }

    /**
     * Derived rather than passed, exactly as PlayerAliasFactory does it: a
     * fixture says what the bracket spelled, and nothing can file a team under
     * a folded form that is not its own.
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this->beforeInstantiate(static function (array $parameters): array {
            $parameters['normalised'] = (new AliasNormaliser())->normalise((string) $parameters['name']);

            return $parameters;
        });
    }
}
