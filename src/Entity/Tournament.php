<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\TournamentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TournamentRepository::class)]
#[ORM\Table(name: 'tournaments')]
class Tournament
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $heldOn;

    /** @var Collection<int, TournamentResult> */
    #[ORM\OneToMany(targetEntity: TournamentResult::class, mappedBy: 'tournament', orphanRemoval: true)]
    private Collection $results;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $challongeUrl = null;

    /**
     * The season this event scores in, or null when it scores nothing.
     *
     * **A tournament scores if and only if it belongs to a season.** An
     * exhibition or international event is archived in full — its snapshot,
     * stages, entrants and matches all persist, and its entrants still resolve
     * to `Player` records — and writes no `TournamentResult` row at all. Not a
     * zero-point one: `TournamentResult` is the scoring record, and a row
     * paying nothing is still a row `getLeagueLeaderboard()` counts against a
     * blader's best fourteen.
     *
     * There is deliberately no `unranked` boolean beside it. A flag can
     * disagree with the relation; the relation is the truth, and `isRanked()`
     * is the only way anything asks the question.
     */
    #[ORM\ManyToOne(inversedBy: 'tournaments')]
    #[ORM\JoinColumn(nullable: true)]
    private ?Season $season = null;

    /**
     * The bracket, archived: its stages, and through them every entrant and
     * every match. Empty for a tournament imported from a placement list and
     * never archived, which is every one of them until #55 backfills.
     *
     * Additive, and deliberately not load-bearing. Nothing on this side scores
     * anything — `results` is still the record the leaderboard is built from —
     * so a tournament with no stages is exactly as correct as it was before
     * the archive existed.
     *
     * @var Collection<int, TournamentStage>
     */
    #[ORM\OneToMany(targetEntity: TournamentStage::class, mappedBy: 'tournament', orphanRemoval: true)]
    private Collection $stages;

    /**
     * The entrants of a 2v2 event, empty for every other kind.
     *
     * Nothing else marks a team event: `is_team` is false in all eighteen
     * captured brackets, the module store not carrying the flag, so a team
     * event is declared at import rather than detected. Holding teams is the
     * declaration's persisted trace.
     *
     * @var Collection<int, TournamentTeam>
     */
    #[ORM\OneToMany(targetEntity: TournamentTeam::class, mappedBy: 'tournament', orphanRemoval: true)]
    private Collection $teams;

    public function __construct()
    {
        $this->results = new ArrayCollection();
        $this->stages = new ArrayCollection();
        $this->teams = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): void
    {
        $this->id = $id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function getHeldOn(): \DateTimeImmutable
    {
        return $this->heldOn;
    }

    public function setHeldOn(\DateTimeImmutable $heldOn): void
    {
        $this->heldOn = $heldOn;
    }

    /** @return Collection<int, TournamentResult> */
    public function getResults(): Collection
    {
        return $this->results;
    }

    /** @param Collection<int, TournamentResult> $results */
    public function setResults(Collection $results): void
    {
        $this->results = $results;
    }

    public function getChallongeUrl(): ?string
    {
        return $this->challongeUrl;
    }

    public function setChallongeUrl(?string $challongeUrl): self
    {
        $this->challongeUrl = $challongeUrl;

        return $this;
    }

    /** @return Collection<int, TournamentStage> */
    public function getStages(): Collection
    {
        return $this->stages;
    }

    public function addStage(TournamentStage $stage): void
    {
        if (!$this->stages->contains($stage)) {
            $this->stages->add($stage);
        }
    }

    public function removeStage(TournamentStage $stage): void
    {
        $this->stages->removeElement($stage);
    }

    /** @return Collection<int, TournamentTeam> */
    public function getTeams(): Collection
    {
        return $this->teams;
    }

    public function addTeam(TournamentTeam $team): void
    {
        if (!$this->teams->contains($team)) {
            $this->teams->add($team);
        }
    }

    public function isTeamEvent(): bool
    {
        return !$this->teams->isEmpty();
    }

    public function getSeason(): ?Season
    {
        return $this->season;
    }

    public function setSeason(?Season $season): self
    {
        $this->season = $season;

        return $this;
    }

    /**
     * Whether this event awards league points.
     *
     * The relation and nothing else. Every caller that used to assume a season
     * asks this first, so "unranked" is one question with one answer rather
     * than a null check repeated in eleven places.
     */
    public function isRanked(): bool
    {
        return null !== $this->season;
    }
}
