<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PlayerAliasRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One spelling a blader has appeared under, and who it is.
 *
 * Across the eighteen captured brackets, two hundred and seven distinct
 * display names belong to about seventy-six people. Normalising case,
 * punctuation and Challonge's `(invitation pending)` suffix folds that to a
 * hundred and twenty-nine; the rest is knowledge, not string handling, and
 * this is where it is kept. `Obelisk` is `Obelix` and `Anzjan` is `Lanzjan`
 * because somebody who was there said so.
 *
 * Two spellings are stored, and the pair is the whole design. `alias` is what
 * the bracket actually said, kept verbatim so a row can be recognised by the
 * person who added it. `normalised` is what it is looked up by, and it carries
 * the unique index: two rows that fold to the same string are the same claim,
 * and the second one is either redundant or a contradiction. Neither is a
 * thing to discover at import time.
 *
 * The constructor takes both because they must agree. Doctrine never calls it
 * when hydrating, so it costs nothing there, and it means no code path can
 * store a spelling under a normalised form that is not its own.
 */
#[ORM\Entity(repositoryClass: PlayerAliasRepository::class)]
#[ORM\Table(name: 'player_aliases')]
#[ORM\UniqueConstraint(name: 'uniq_player_alias_normalised', columns: ['normalised'])]
class PlayerAlias
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Player::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Player $player;

    /** As the bracket spelled it. */
    #[ORM\Column(length: 255)]
    private string $alias;

    /** What it is looked up by. */
    #[ORM\Column(length: 255)]
    private string $normalised;

    #[ORM\Column(length: 32, enumType: PlayerAliasSource::class)]
    private PlayerAliasSource $source;

    #[ORM\Column]
    private \DateTimeImmutable $recordedAt;

    public function __construct(
        Player $player,
        string $alias,
        string $normalised,
        PlayerAliasSource $source,
        ?\DateTimeImmutable $recordedAt = null,
    ) {
        $this->player = $player;
        $this->alias = $alias;
        $this->normalised = $normalised;
        $this->source = $source;
        $this->recordedAt = $recordedAt ?? new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    /**
     * Moving an alias is the correction path: a spelling attached to the wrong
     * blader is repointed rather than deleted and typed again, so the row that
     * records where it came from survives being wrong once.
     */
    public function setPlayer(Player $player): void
    {
        $this->player = $player;
    }

    public function getAlias(): string
    {
        return $this->alias;
    }

    public function getNormalised(): string
    {
        return $this->normalised;
    }

    public function getSource(): PlayerAliasSource
    {
        return $this->source;
    }

    public function setSource(PlayerAliasSource $source): void
    {
        $this->source = $source;
    }

    public function getRecordedAt(): \DateTimeImmutable
    {
        return $this->recordedAt;
    }
}
