<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerMergeRedirectRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable destination for a profile whose player row has been merged away.
 *
 * Keyed on the old id and carrying the old slug, because both were public
 * URLs: `/season/1/player/42` was one before #95 and `/player/markinu` is one
 * after it. A merge deletes the losing row, so neither can be resolved by
 * looking the blader up — the redirect is the only thing that remembers them,
 * and both forms have to keep working.
 */
#[ORM\Entity(repositoryClass: PlayerMergeRedirectRepository::class)]
#[ORM\Table(name: 'player_merge_redirects')]
class PlayerMergeRedirect
{
    #[ORM\Id]
    #[ORM\Column(name: 'old_player_id')]
    private int $oldPlayerId;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'survivor_id', nullable: false)]
    private Player $survivor;

    /**
     * The canonical URL the losing blader had.
     *
     * Not unique: a blader merged away twice — merged into somebody who is
     * then merged again — leaves a chain, and each hop keeps the slug it was
     * pointing at. The lookup takes the newest, which is the one that resolves.
     */
    #[ORM\Column(length: 255)]
    private string $oldSlug;

    public function __construct(int $oldPlayerId, string $oldSlug, Player $survivor)
    {
        $this->oldPlayerId = $oldPlayerId;
        $this->oldSlug = $oldSlug;
        $this->survivor = $survivor;
    }

    public function getOldSlug(): string
    {
        return $this->oldSlug;
    }

    public function getOldPlayerId(): int
    {
        return $this->oldPlayerId;
    }

    public function getSurvivor(): Player
    {
        return $this->survivor;
    }

    public function pointsTo(Player $survivor): void
    {
        $this->survivor = $survivor;
    }
}
