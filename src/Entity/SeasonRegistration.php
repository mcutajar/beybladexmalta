<?php

namespace App\Entity;

use App\Repository\SeasonRegistrationRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeasonRegistrationRepository::class)]
#[ORM\Table(name: 'season_registrations')]
#[ORM\UniqueConstraint(name: 'player_season_unique', columns: ['player_id', 'season_id'])]
class SeasonRegistration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Player $player = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Season $season = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $paid = false;

    public function getId(): ?int { return $this->id; }

    public function getPlayer(): ?Player { return $this->player; }
    public function setPlayer(?Player $player): self { $this->player = $player; return $this; }

    public function getSeason(): ?Season { return $this->season; }
    public function setSeason(?Season $season): self { $this->season = $season; return $this; }

    public function isPaid(): bool { return $this->paid; }
    public function setPaid(bool $paid): self { $this->paid = $paid; return $this; }
}
