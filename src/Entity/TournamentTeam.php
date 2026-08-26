<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TournamentTeamRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entrant of a 2v2 event: the name the bracket carried, where it finished,
 * and the bladers who were in it.
 *
 * A team belongs to the event rather than to the league, which is the whole
 * reason this is not a `Team` entity with a roster hanging off it. Sk3lli was
 * in `legion` on 11 July and in `Lopez` on 19 July; Belti and Amanda were
 * `the bakers` and then `Bastjanizi`. The pairing is a fact about one evening,
 * so the tournament is part of the key.
 *
 * It is a sibling of the alias table and stores its name the same way, for the
 * same reason. `name` is what the bracket said — `legion ()`, `infernal rage
 * (invitation pending)` — kept verbatim so a row is recognisable to whoever
 * entered it. `normalised` is what it is looked up by, and it carries the
 * unique index, scoped to the tournament: two entrants of one bracket that
 * fold to the same string are the same entrant twice.
 *
 * **A team with no members is unclaimed, and that is a record rather than a
 * gap.** `JG` and `melhina` finished tenth and eleventh on 11 July and nobody
 * has said who was in either; they keep their rank, score nothing, and can be
 * claimed later through `app:team claim`. Without a row for the team itself
 * they would leave no trace at all, which is how the eleventh-place team
 * vanished in the first place. One member is allowed too — a half-known team
 * costs nothing and awards the known half their points.
 *
 * Scoring is still `TournamentResult`, written one per member at the team's
 * rank, so the leaderboard never learns that team events exist.
 */
#[ORM\Entity(repositoryClass: TournamentTeamRepository::class)]
#[ORM\Table(name: 'tournament_teams')]
#[ORM\UniqueConstraint(name: 'uniq_tournament_team_normalised', columns: ['tournament_id', 'normalised'])]
class TournamentTeam
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'teams')]
    #[ORM\JoinColumn(nullable: false)]
    private Tournament $tournament;

    /** As the bracket spelled it. */
    #[ORM\Column(length: 255)]
    private string $name;

    /** What it is looked up by. */
    #[ORM\Column(length: 255)]
    private string $normalised;

    #[ORM\Column]
    private int $rank;

    /** @var Collection<int, TournamentTeamMember> */
    #[ORM\OneToMany(targetEntity: TournamentTeamMember::class, mappedBy: 'team', cascade: ['persist'], orphanRemoval: true)]
    private Collection $members;

    public function __construct(
        Tournament $tournament,
        string $name,
        string $normalised,
        int $rank,
    ) {
        $this->tournament = $tournament;
        $this->name = $name;
        $this->normalised = $normalised;
        $this->rank = $rank;
        $this->members = new ArrayCollection();

        $tournament->addTeam($this);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournament(): Tournament
    {
        return $this->tournament;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getNormalised(): string
    {
        return $this->normalised;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    /** @return Collection<int, TournamentTeamMember> */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    /**
     * @return list<Player>
     */
    public function getBladers(): array
    {
        return array_values(array_map(
            static fn (TournamentTeamMember $member): Player => $member->getPlayer(),
            $this->members->toArray(),
        ));
    }

    /**
     * Puts a blader in the team, or leaves them there.
     *
     * The no-op matters because a claim is a ledger line like any other, and a
     * replayed one must not attach the same person twice.
     */
    public function addMember(Player $player): ?TournamentTeamMember
    {
        if ($this->hasMember($player)) {
            return null;
        }

        $member = new TournamentTeamMember($this, $player);
        $this->members->add($member);

        return $member;
    }

    public function hasMember(Player $player): bool
    {
        foreach ($this->members as $member) {
            if ($member->getPlayer() === $player) {
                return true;
            }
        }

        return false;
    }

    public function isClaimed(): bool
    {
        return !$this->members->isEmpty();
    }
}
