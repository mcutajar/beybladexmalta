<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\PerformanceBladeLane;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'performance_blades')]
#[ORM\UniqueConstraint(name: 'uniq_performance_blade_lane', columns: ['tracker_id', 'lane'])]
class PerformanceBlade
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'blades')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private PerformanceTracker $tracker;

    #[ORM\Column(length: 1, enumType: PerformanceBladeLane::class)]
    private PerformanceBladeLane $lane;

    #[ORM\Column(length: 100)]
    private string $displayName;

    #[ORM\Column(length: 100)]
    private string $blade;

    #[ORM\Column(length: 60)]
    private string $ratchet;

    #[ORM\Column(length: 60)]
    private string $bit;

    #[ORM\Column(length: 16, nullable: true)]
    private ?string $colour;

    public function __construct(PerformanceTracker $tracker, PerformanceBladeLane $lane, string $displayName, string $blade, string $ratchet, string $bit, ?string $colour)
    {
        $this->tracker = $tracker;
        $this->lane = $lane;
        $this->configure($displayName, $blade, $ratchet, $bit, $colour);
        $tracker->addBlade($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLane(): PerformanceBladeLane
    {
        return $this->lane;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getBlade(): string
    {
        return $this->blade;
    }

    public function getRatchet(): string
    {
        return $this->ratchet;
    }

    public function getBit(): string
    {
        return $this->bit;
    }

    public function getColour(): ?string
    {
        return $this->colour;
    }

    public function combination(): string
    {
        $parts = array_filter([$this->blade, $this->ratchet, $this->bit], static fn (string $part): bool => '' !== $part);

        return implode(' · ', $parts);
    }

    public function configure(string $displayName, string $blade, string $ratchet, string $bit, ?string $colour): void
    {
        $this->displayName = trim($displayName);
        $this->blade = trim($blade);
        $this->ratchet = trim($ratchet);
        $this->bit = trim($bit);
        $colour = null === $colour ? '' : trim($colour);
        $this->colour = '' === $colour ? null : $colour;
    }
}
