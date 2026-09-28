<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\PerformanceBladeLane;
use App\Repository\PerformanceTrackerRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PerformanceTrackerRepository::class)]
#[ORM\Table(name: 'performance_trackers')]
#[ORM\UniqueConstraint(name: 'uniq_performance_tracker_edit_hash', columns: ['edit_token_hash'])]
#[ORM\UniqueConstraint(name: 'uniq_performance_tracker_share_id', columns: ['share_id'])]
class PerformanceTracker
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $editTokenHash;

    #[ORM\Column(length: 64)]
    private string $shareId;

    #[ORM\Column(length: 160)]
    private string $tournamentName;

    #[ORM\Column]
    private \DateTimeImmutable $heldOn;

    #[ORM\Column(length: 100)]
    private string $playerName;

    #[ORM\Column(length: 160, nullable: true)]
    private ?string $location;

    #[ORM\Column]
    private int $expectedMatches;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PerformanceBlade> */
    #[ORM\OneToMany(targetEntity: PerformanceBlade::class, mappedBy: 'tracker', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['lane' => 'ASC'])]
    private Collection $blades;

    /** @var Collection<int, PerformanceMatch> */
    #[ORM\OneToMany(targetEntity: PerformanceMatch::class, mappedBy: 'tracker', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $matches;

    public function __construct(
        string $editTokenHash,
        string $shareId,
        string $tournamentName,
        \DateTimeImmutable $heldOn,
        string $playerName,
        ?string $location,
        int $expectedMatches,
    ) {
        $this->editTokenHash = $editTokenHash;
        $this->shareId = $shareId;
        $this->blades = new ArrayCollection();
        $this->matches = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->configure($tournamentName, $heldOn, $playerName, $location, $expectedMatches);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getShareId(): string
    {
        return $this->shareId;
    }

    public function getTournamentName(): string
    {
        return $this->tournamentName;
    }

    public function getHeldOn(): \DateTimeImmutable
    {
        return $this->heldOn;
    }

    public function getPlayerName(): string
    {
        return $this->playerName;
    }

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function getExpectedMatches(): int
    {
        return $this->expectedMatches;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function configure(string $tournamentName, \DateTimeImmutable $heldOn, string $playerName, ?string $location, int $expectedMatches): void
    {
        $this->tournamentName = trim($tournamentName);
        $this->heldOn = $heldOn;
        $this->playerName = trim($playerName);
        $this->location = self::optional($location);
        $this->expectedMatches = $expectedMatches;
        $this->touch();
    }

    /** @return Collection<int, PerformanceBlade> */
    public function getBlades(): Collection
    {
        return $this->blades;
    }

    public function blade(PerformanceBladeLane $lane): PerformanceBlade
    {
        foreach ($this->blades as $blade) {
            if ($blade->getLane() === $lane) {
                return $blade;
            }
        }

        throw new \LogicException(sprintf('Tracker has no blade in lane %s.', $lane->value));
    }

    public function addBlade(PerformanceBlade $blade): void
    {
        if (3 <= $this->blades->count()) {
            throw new \LogicException('A tracker has exactly three blade lanes.');
        }

        foreach ($this->blades as $existing) {
            if ($existing->getLane() === $blade->getLane()) {
                throw new \LogicException(sprintf('Blade lane %s is already occupied.', $blade->getLane()->value));
            }
        }

        $this->blades->add($blade);
    }

    /** @return Collection<int, PerformanceMatch> */
    public function getMatches(): Collection
    {
        return $this->matches;
    }

    public function match(int $sequence): ?PerformanceMatch
    {
        foreach ($this->matches as $match) {
            if ($match->getSequence() === $sequence) {
                return $match;
            }
        }

        return null;
    }

    public function addMatch(PerformanceMatch $match): void
    {
        if (null !== $this->match($match->getSequence())) {
            throw new \LogicException(sprintf('Match %d already exists.', $match->getSequence()));
        }

        $this->matches->add($match);
        $this->touch();
    }

    public function removeMatch(PerformanceMatch $match): void
    {
        $this->matches->removeElement($match);
        $this->touch();
    }

    public function nextMatchSequence(): int
    {
        $maximum = 0;
        foreach ($this->matches as $match) {
            $maximum = max($maximum, $match->getSequence());
        }

        return $maximum + 1;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    private static function optional(?string $value): ?string
    {
        $value = null === $value ? '' : trim($value);

        return '' === $value ? null : $value;
    }
}
