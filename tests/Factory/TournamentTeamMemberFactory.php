<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\TournamentTeamMember;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<TournamentTeamMember>
 */
final class TournamentTeamMemberFactory extends PersistentObjectFactory
{
    #[\Override]
    public static function class(): string
    {
        return TournamentTeamMember::class;
    }

    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'team' => TournamentTeamFactory::new(),
            'player' => PlayerFactory::new(),
        ];
    }
}
