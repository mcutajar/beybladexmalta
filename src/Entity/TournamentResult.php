<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TournamentResultRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TournamentResultRepository::class)]
#[ORM\Table(name: 'tournament_results')]
class TournamentResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'results')]
    #[ORM\JoinColumn(nullable: false)]
    private Tournament $tournament;

    #[ORM\ManyToOne(inversedBy: 'results')]
    #[ORM\JoinColumn(nullable: false)]
    private Player $player;

    #[ORM\Column]
    private int $rank;

    #[ORM\Column]
    private int $f1Points = 0;

    #[ORM\Column]
    private int $bonusPoints = 0;

    #[ORM\Column]
    private int $totalPoints = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function setRank(int $rank): void
    {
        $this->rank = $rank;
    }

    public function getTournament(): Tournament
    {
        return $this->tournament;
    }

    public function setTournament(Tournament $tournament): self
    {
        $this->tournament = $tournament;

        return $this;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function setPlayer(Player $player): self
    {
        $this->player = $player;

        return $this;
    }

    public function getF1Points(): int
    {
        return $this->f1Points;
    }

    public function setF1Points(int $f1Points): self
    {
        $this->f1Points = $f1Points;
        $this->updateTotal();

        return $this;
    }

    public function getBonusPoints(): int
    {
        return $this->bonusPoints;
    }

    public function setBonusPoints(int $bonusPoints): self
    {
        $this->bonusPoints = $bonusPoints;
        $this->updateTotal();

        return $this;
    }

    public function getTotalPoints(): int
    {
        return $this->totalPoints;
    }

    private function updateTotal(): void
    {
        $this->totalPoints = $this->f1Points + $this->bonusPoints;
    }
}
