<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One blader in one event's team.
 *
 * Nothing else: a roster line carries no result of its own, because a 2v2
 * bracket records only the aggregate of each team match — `games [[7,2],[5,7],
 * [5,7]] score [1,2]` is three matchups and the sets won, and nothing in it
 * says which half of either team played which. Points reach the blader through
 * a `TournamentResult` written at the team's rank instead.
 *
 * There are no setters and no unclaim. A member is added by an import or a
 * claim and both are ledger lines; taking one out again is a correction the
 * domain does not have yet, and inventing one on the entity is where AGENTS.md
 * says such things do not live.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tournament_team_members')]
#[ORM\UniqueConstraint(name: 'uniq_tournament_team_member', columns: ['team_id', 'player_id'])]
class TournamentTeamMember
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'members')]
    #[ORM\JoinColumn(name: 'team_id', nullable: false)]
    private TournamentTeam $team;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Player $player;

    public function __construct(TournamentTeam $team, Player $player)
    {
        $this->team = $team;
        $this->player = $player;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTeam(): TournamentTeam
    {
        return $this->team;
    }

    public function getPlayer(): Player
    {
        return $this->player;
    }

    public function belongsTo(Player $player): void
    {
        $this->player = $player;
    }
}
