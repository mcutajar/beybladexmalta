<?php

declare(strict_types=1);

namespace App\Tests\Factory;

use App\Entity\Player;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Player>
 */
final class PlayerFactory extends PersistentObjectFactory
{
    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#factories-as-services
     *
     * @todo inject services if required
     */
    public function __construct()
    {
    }

    #[\Override]
    public static function class(): string
    {
        return Player::class;
    }

    /**
     * @see https://symfony.com/bundles/ZenstruckFoundryBundle/current/index.html#model-factories
     */
    #[\Override]
    protected function defaults(): array|callable
    {
        return [
            'name' => self::faker()->text(255),
        ];
    }

    /**
     * The slug is derived here rather than defaulted, so a test that names a
     * blader gets the URL the application would have given them and can assert
     * against it without restating the rule.
     *
     * `PlayerSlugs` is not called: it reads the database to resolve
     * collisions, and a factory that queried on every instantiation would make
     * building a league of thirty bladers thirty round trips. A unique suffix
     * keeps the column's index happy where two faker names fold together.
     */
    #[\Override]
    protected function initialize(): static
    {
        return $this->afterInstantiate(static function (Player $player): void {
            try {
                $player->getSlug();

                // A test that named one explicitly keeps it.
                return;
            } catch (\Error) {
                // Uninitialised, which is every other blader.
            }

            $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($player->getName())), '-');

            $player->setSlug('' === $slug ? 'blader-'.bin2hex(random_bytes(4)) : $slug);
        });
    }
}
