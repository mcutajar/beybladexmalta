<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\PerformanceResultValue;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'performance_rounds')]
#[ORM\UniqueConstraint(name: 'uniq_performance_round_sequence', columns: ['match_id', 'sequence'])]
class PerformanceRound
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'rounds')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PerformanceMatch $match;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PerformanceResult> */
    #[ORM\OneToMany(targetEntity: PerformanceResult::class, mappedBy: 'round', cascade: ['persist'], orphanRemoval: true)]
    private Collection $results;

    public function __construct(PerformanceMatch $match, int $sequence)
    {
        $this->match = $match;
        $this->sequence = $sequence;
        $this->updatedAt = new \DateTimeImmutable();
        $this->results = new ArrayCollection();
        $match->addRound($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMatch(): PerformanceMatch
    {
        return $this->match;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /** @return Collection<int, PerformanceResult> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    public function resultFor(PerformanceBlade $blade): PerformanceResult
    {
        foreach ($this->results as $result) {
            if ($result->getBlade() === $blade) {
                return $result;
            }
        }

        throw new \LogicException(sprintf('Round %d has no result for lane %s.', $this->sequence, $blade->getLane()->value));
    }

    public function addResult(PerformanceResult $result): void
    {
        $this->results->add($result);
    }

    public function isComplete(): bool
    {
        if (3 !== $this->results->count()) {
            return false;
        }

        foreach ($this->results as $result) {
            if (PerformanceResultValue::NotRecorded === $result->getValue()) {
                return false;
            }
        }

        return true;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
        $this->match->touch();
    }
}
