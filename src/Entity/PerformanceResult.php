<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\PerformanceResultValue;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'performance_results')]
#[ORM\UniqueConstraint(name: 'uniq_performance_result_match_blade', columns: ['match_id', 'blade_id'])]
class PerformanceResult
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'results')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PerformanceMatch $match;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PerformanceBlade $blade;

    #[ORM\Column(length: 16, enumType: PerformanceResultValue::class)]
    private PerformanceResultValue $value = PerformanceResultValue::NotRecorded;

    #[ORM\Column]
    private \DateTimeImmutable $changedAt;

    public function __construct(PerformanceMatch $match, PerformanceBlade $blade)
    {
        $this->match = $match;
        $this->blade = $blade;
        $this->changedAt = new \DateTimeImmutable();
        $match->addResult($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMatch(): PerformanceMatch
    {
        return $this->match;
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
        $this->match->touch();
    }
}
