<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PlayerRepository::class)]
#[ORM\Table(name: 'players')]
class Player
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255, unique: true)]
    private string $name;

    /**
     * The blader's canonical, public URL.
     *
     * **Persisted, not recalculated** from the display name on every request.
     * Player identity is independent of seasons and of spelling: a harmless
     * name correction — a capital letter, a stray space — would otherwise
     * silently break a public URL somebody has shared. The slug is assigned
     * once, when the blader is put on record, and stays.
     *
     * Non-nullable, so `PlayerSlugs` assigns one at every site that creates a
     * blader. The column's nullability and this property's have to agree:
     * PHPStan reads the real Doctrine mapping.
     */
    #[ORM\Column(length: 255, unique: true)]
    private string $slug;

    /** @var Collection<int, TournamentResult> */
    #[ORM\OneToMany(targetEntity: TournamentResult::class, mappedBy: 'player')]
    private Collection $results;

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

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    /**
     * Only `PlayerSlugs` and the ORM should call this. Changing a slug that is
     * already public breaks every link to it, which is the thing persisting it
     * exists to prevent.
     */
    public function setSlug(string $slug): void
    {
        $this->slug = $slug;
    }

    /** @return Collection<int, TournamentResult> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    /** @param Collection<int, TournamentResult> $results */
    public function setResults(Collection $results): void
    {
        $this->results = $results;
    }
}
