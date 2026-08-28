<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerAliasRejectionRepository;
use Doctrine\ORM\Mapping as ORM;

/** A suggestion somebody has considered and said is the wrong person. */
#[ORM\Entity(repositoryClass: PlayerAliasRejectionRepository::class)]
#[ORM\Table(name: 'player_alias_rejections')]
#[ORM\UniqueConstraint(name: 'uniq_player_alias_rejection', columns: ['player_id', 'normalised'])]
class PlayerAliasRejection
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Player $player;

    /** As the bracket spelled it. */
    #[ORM\Column(length: 255)]
    private string $spelling;

    /** What the resolver looks it up by. */
    #[ORM\Column(length: 255)]
    private string $normalised;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct(Player $player, string $spelling, string $normalised, ?\DateTimeImmutable $recordedAt = null)
    {
        $this->player = $player;
        $this->spelling = $spelling;
        $this->normalised = $normalised;
        $this->recordedAt = $recordedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function getSpelling(): string
    {
        return $this->spelling;
    }

    public function getNormalised(): string
    {
        return $this->normalised;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
