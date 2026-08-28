<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerMergeRedirectRepository;
use Doctrine\ORM\Mapping as ORM;

/** Durable destination for a profile whose player row has been merged away. */
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

    public function __construct(int $oldPlayerId, Player $survivor)
    {
        $this->oldPlayerId = $oldPlayerId;
        $this->survivor = $survivor;
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
