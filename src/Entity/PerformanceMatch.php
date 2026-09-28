<?php

declare(strict_types=1);

namespace App\Entity;

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

    #[ORM\Column(name: 'stage_label', length: 100, nullable: true)]
    private ?string $stage = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $playedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** @var Collection<int, PerformanceRound> */
    #[ORM\OneToMany(targetEntity: PerformanceRound::class, mappedBy: 'match', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    private Collection $rounds;

    public function __construct(PerformanceTracker $tracker, int $sequence)
    {
        $this->tracker = $tracker;
        $this->sequence = $sequence;
        $this->updatedAt = new \DateTimeImmutable();
        $this->rounds = new ArrayCollection();
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

    public function getStage(): ?string
    {
        return $this->stage;
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

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    /** @return Collection<int, PerformanceRound> */
    public function getRounds(): Collection
    {
        return $this->rounds;
    }

    public function round(int $sequence): ?PerformanceRound
    {
        foreach ($this->rounds as $round) {
            if ($round->getSequence() === $sequence) {
                return $round;
            }
        }

        return null;
    }

    public function addRound(PerformanceRound $round): void
    {
        if (null !== $this->round($round->getSequence())) {
            throw new \LogicException(sprintf('Round %d already exists in match %d.', $round->getSequence(), $this->sequence));
        }

        $this->rounds->add($round);
        $this->finishedAt = null;
        $this->touch();
    }

    public function removeRound(PerformanceRound $round): void
    {
        $this->rounds->removeElement($round);
        $this->finishedAt = null;
        $this->touch();
    }

    public function nextRoundSequence(): int
    {
        $maximum = 0;
        foreach ($this->rounds as $round) {
            $maximum = max($maximum, $round->getSequence());
        }

        return $maximum + 1;
    }

    public function isComplete(): bool
    {
        return null !== $this->finishedAt;
    }

    public function finish(): void
    {
        if ($this->rounds->isEmpty()) {
            throw new \DomainException(sprintf('Match %d needs at least one round.', $this->sequence));
        }

        foreach ($this->rounds as $round) {
            if (!$round->isComplete()) {
                throw new \DomainException(sprintf('Finish every result in round %d first.', $round->getSequence()));
            }
        }

        $this->finishedAt = new \DateTimeImmutable();
        $this->touch();
    }

    public function reopen(): void
    {
        $this->finishedAt = null;
        $this->touch();
    }

    public function configure(?string $opponent, ?string $finalScore, ?string $stage, ?string $notes, ?\DateTimeImmutable $playedAt): void
    {
        $this->opponent = self::optional($opponent);
        $this->finalScore = self::optional($finalScore);
        $this->stage = self::optional($stage);
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
