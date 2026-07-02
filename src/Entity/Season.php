<?php

namespace App\Entity;

use App\Repository\SeasonRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SeasonRepository::class)]
#[ORM\Table(name: 'seasons')]
class Season
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\OneToMany(targetEntity: Tournament::class, mappedBy: 'season')]
    private Collection $tournaments;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $requiresPayment = false;

    public function __construct()
    {
        $this->tournaments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): self { $this->slug = $slug; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): self { $this->name = $name; return $this; }

    /** @return Collection<int, Tournament> */
    public function getTournaments(): Collection { return $this->tournaments; }

    public function requiresPayment(): bool
    {
        return $this->requiresPayment;
    }

    public function setRequiresPayment(bool $requiresPayment): self
    {
        $this->requiresPayment = $requiresPayment;
        return $this;
    }
}
