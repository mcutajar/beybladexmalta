<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\PerformanceResultValue;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'performance_results')]
#[ORM\UniqueConstraint(name: 'uniq_performance_result_round_blade', columns: ['round_id', 'blade_id'])]
class PerformanceResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'results')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PerformanceRound $round;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PerformanceBlade $blade;

    #[ORM\Column(length: 16, enumType: PerformanceResultValue::class)]
    private PerformanceResultValue $value = PerformanceResultValue::NotRecorded;

    #[ORM\Column]
    private \DateTimeImmutable $changedAt;

    public function __construct(PerformanceRound $round, PerformanceBlade $blade)
    {
        $this->round = $round;
        $this->blade = $blade;
        $this->changedAt = new \DateTimeImmutable();
        $round->addResult($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRound(): PerformanceRound
    {
        return $this->round;
    }

    public function getBlade(): PerformanceBlade
    {
        return $this->blade;
    }

    public function getValue(): PerformanceResultValue
    {
        return $this->value;
    }

    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }

    public function record(PerformanceResultValue $value): void
    {
        $this->value = $value;
        $this->changedAt = new \DateTimeImmutable();
        $this->round->touch();
    }
}
