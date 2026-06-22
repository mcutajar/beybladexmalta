<?php

namespace App\Entity;

use App\Repository\TournamentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TournamentRepository::class)]
#[ORM\Table(name: 'tournaments')]
class Tournament
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'date')]
    private ?\DateTimeInterface $heldOn = null;

    #[ORM\OneToMany(targetEntity: TournamentResult::class, mappedBy: 'tournament', orphanRemoval: true)]
    private Collection $results;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $challongeUrl = null;

    public function __construct()
    {
        $this->results = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }

    public function getHeldOn(): ?\DateTimeInterface
    {
        return $this->heldOn;
    }

    public function setHeldOn(?\DateTimeInterface $heldOn): void
    {
        $this->heldOn = $heldOn;
    }

    public function getResults(): Collection
    {
        return $this->results;
    }

    public function setResults(Collection $results): void
    {
        $this->results = $results;
    }

    public function getChallongeUrl(): ?string
    {
        return $this->challongeUrl;
    }

    public function setChallongeUrl(?string $challongeUrl): self
    {
        $this->challongeUrl = $challongeUrl;

        return $this;
    }
}
