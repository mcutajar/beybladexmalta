<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\PerformanceResultValue;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'performance_matches')]
#[ORM\UniqueConstraint(name: 'uniq_performance_match_sequence', columns: ['tracker_id', 'sequence'])]
class PerformanceMatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'matches')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PerformanceTracker $tracker;

    #[ORM\Column]
    private int $sequence;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $opponent = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $finalScore = null;

    #[ORM\Column(name: 'round_label', length: 100, nullable: true)]
    private ?string $round = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $playedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, PerformanceResult> */
    #[ORM\OneToMany(targetEntity: PerformanceResult::class, mappedBy: 'match', cascade: ['persist'], orphanRemoval: true)]
    private Collection $results;

    public function __construct(PerformanceTracker $tracker, int $sequence)
    {
        $this->tracker = $tracker;
        $this->sequence = $sequence;
        $this->updatedAt = new \DateTimeImmutable();
        $this->results = new ArrayCollection();
        $tracker->addMatch($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function getOpponent(): ?string
    {
        return $this->opponent;
    }

    public function getFinalScore(): ?string
    {
        return $this->finalScore;
    }

    public function getRound(): ?string
    {
        return $this->round;
    }

    public function getNotes(): ?string
    {
        return $this->notes;
    }

    public function getPlayedAt(): ?\DateTimeImmutable
    {
        return $this->playedAt;
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

        throw new \LogicException(sprintf('Match %d has no result for lane %s.', $this->sequence, $blade->getLane()->value));
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

    public function configure(?string $opponent, ?string $finalScore, ?string $round, ?string $notes, ?\DateTimeImmutable $playedAt): void
    {
        $this->opponent = self::optional($opponent);
        $this->finalScore = self::optional($finalScore);
        $this->round = self::optional($round);
        $this->notes = self::optional($notes);
        $this->playedAt = $playedAt;
        $this->touch();
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
