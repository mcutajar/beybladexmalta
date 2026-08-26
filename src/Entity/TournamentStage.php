<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\ChallongeStageKind;
use App\Repository\TournamentStageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One stage of an event's bracket: the Swiss rounds everybody played, the cut
 * that followed, or the single stage that was the whole tournament.
 *
 * This is the root of the archive, and the archive is additive. Nothing here
 * scores anything: `TournamentResult` remains the record the leaderboard is
 * built from, with the same ranks and the same matrix, and a tournament with
 * no stages at all is exactly as correct as it was before this table existed.
 * What the archive adds is the nine hundred and fifty-one matches the
 * placement list threw away.
 *
 * The kind is `App\Dto\ChallongeStageKind` rather than a second enum spelled
 * the same way. A stage is a group, a final or a single because that is what
 * Challonge made it, and two copies of that vocabulary is two chances for the
 * reader and the archive to disagree about what `single` means.
 *
 * `position` is the order the stages were played and carries the unique index
 * with the tournament, because that is the only thing about a stage that is
 * stable. A bracket edited upstream can gain a round, rename itself or change
 * format; what it cannot do is reorder the stage everybody played and the cut
 * that came out of it.
 */
#[ORM\Entity(repositoryClass: TournamentStageRepository::class)]
#[ORM\Table(name: 'tournament_stages')]
#[ORM\UniqueConstraint(name: 'uniq_tournament_stage_position', columns: ['tournament_id', 'position'])]
class TournamentStage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'stages')]
    #[ORM\JoinColumn(nullable: false)]
    private Tournament $tournament;

    #[ORM\Column]
    private int $position;

    #[ORM\Column(length: 16, enumType: ChallongeStageKind::class)]
    private ChallongeStageKind $kind;

    /** Challonge's own name for it — "Group A", or nothing at all for a cut. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $name = null;

    /** `swiss`, `single elimination`, `round robin`. */
    #[ORM\Column(length: 64)]
    private string $format;

    #[ORM\Column]
    private int $rounds = 0;

    /** @var Collection<int, TournamentParticipant> */
    #[ORM\OneToMany(targetEntity: TournamentParticipant::class, mappedBy: 'stage', cascade: ['persist'], orphanRemoval: true)]
    private Collection $participants;

    /**
     * Matches cascade but are **not** orphan-removed, and the difference is
     * load-bearing.
     *
     * `PersistentCollection::removeElement()` schedules the orphan removal
     * there and then; only `PersistentCollection::add()` cancels it again. A
     * stage created during this same run holds a plain `ArrayCollection`,
     * which has no such hook — so a match moved out of an existing stage and
     * into a new one would be updated and then deleted in the same flush, and
     * the archive would report success for a row the database no longer has.
     *
     * A bracket restructured upstream from one stage into a group and a cut
     * does exactly that. So the collection persists and cascades a delete of
     * the stage itself, and a match the bracket has genuinely dropped is
     * removed by name, through `TournamentStageRepository::discardMatch()`.
     *
     * `participants` keeps orphan removal because an entrant never moves: the
     * group stage and the cut number their entrants in disjoint spaces, so a
     * blader who played both is two rows and neither one travels.
     *
     * @var Collection<int, TournamentMatch>
     */
    #[ORM\OneToMany(targetEntity: TournamentMatch::class, mappedBy: 'stage', cascade: ['persist', 'remove'])]
    private Collection $matches;

    public function __construct(
        Tournament $tournament,
        int $position,
        ChallongeStageKind $kind,
    ) {
        $this->tournament = $tournament;
        $this->position = $position;
        $this->kind = $kind;
        $this->format = '';
        $this->participants = new ArrayCollection();
        $this->matches = new ArrayCollection();

        $tournament->addStage($this);
    }

    /**
     * Everything the bracket says about the stage, as it says it now.
     *
     * The kind is in here rather than fixed at construction, even though it
     * reads like identity. A one-stage event that later gains a cut turns
     * position zero from `single` into `group` without a single match
     * changing, and that is the same stage corrected rather than a different
     * one — which is why `position` alone is the key.
     */
    public function transcribe(ChallongeStageKind $kind, ?string $name, string $format, int $rounds): void
    {
        $this->kind = $kind;
        $this->name = $name;
        $this->format = $format;
        $this->rounds = $rounds;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournament(): Tournament
    {
        return $this->tournament;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getKind(): ChallongeStageKind
    {
        return $this->kind;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function getRounds(): int
    {
        return $this->rounds;
    }

    /** @return Collection<int, TournamentParticipant> */
    public function getParticipants(): Collection
    {
        return $this->participants;
    }

    /**
     * The entrant this stage knows by that Challonge id, or nothing.
     *
     * Ids are per stage, which is Challonge's doing rather than ours: the
     * group stage and the cut of one bracket use disjoint id spaces, so a
     * blader who played both is two rows here.
     */
    public function participant(int $challongeId): ?TournamentParticipant
    {
        foreach ($this->participants as $participant) {
            if ($participant->getChallongeId() === $challongeId) {
                return $participant;
            }
        }

        return null;
    }

    public function addParticipant(TournamentParticipant $participant): void
    {
        if (!$this->participants->contains($participant)) {
            $this->participants->add($participant);
        }
    }

    public function removeParticipant(TournamentParticipant $participant): void
    {
        $this->participants->removeElement($participant);
    }

    /** @return Collection<int, TournamentMatch> */
    public function getMatches(): Collection
    {
        return $this->matches;
    }

    public function addMatch(TournamentMatch $match): void
    {
        if (!$this->matches->contains($match)) {
            $this->matches->add($match);
        }
    }

    public function removeMatch(TournamentMatch $match): void
    {
        $this->matches->removeElement($match);
    }
}
