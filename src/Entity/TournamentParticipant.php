<?php

declare(strict_types=1);

namespace App\Entity;

use App\Dto\ChallongeRecord;
use Doctrine\ORM\Mapping as ORM;

/**
 * One entrant of one stage: who the bracket said they were, and what the
 * standings table said they did.
 *
 * Two things are joined here that the snapshot deliberately keeps apart. The
 * participant list says who was seeded where; the standings table says who
 * finished where, with what record — and a standings row does not reliably
 * carry the participant's name, because a blader who linked their Challonge
 * account is rendered as that account instead. `ChallongeStandingsResolver`
 * joins the two through the match ids in the row's history cell, and this is
 * the pair after the join.
 *
 * **Everybody is archived, not just the ten who scored.** Ranks below eleven
 * pay no league points and they are half the matches; a blader's record is
 * wrong without them.
 *
 * `player` is the blader this entrant turned out to be, and it is nullable
 * because resolution never invents anyone. A display name nobody has told us
 * about stays here under the spelling the bracket used, attached to nobody,
 * until somebody files an alias — at which point re-archiving picks it up.
 * That is the same rule the import obeys, and it is why an archive can be run
 * against a bracket full of unfamiliar names without quietly growing the
 * league by six people.
 *
 * Nothing here is scoring. The rank in this table is the entrant's place in
 * *this stage*, which for a cut is a placing among eight; the event's
 * finishing order is `TournamentResult` and stays untouched.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tournament_participants')]
#[ORM\UniqueConstraint(name: 'uniq_tournament_participant', columns: ['stage_id', 'challonge_id'])]
class TournamentParticipant
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'participants')]
    #[ORM\JoinColumn(name: 'stage_id', nullable: false)]
    private TournamentStage $stage;

    /**
     * Challonge's id for them *within this stage*. Half the key: the group
     * stage and the cut number their entrants in unrelated spaces.
     */
    #[ORM\Column]
    private int $challongeId;

    /** As the bracket spelled it. */
    #[ORM\Column(length: 255)]
    private string $name;

    /** The Challonge account rendered in place of the name, when there was one. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $challongeUser = null;

    #[ORM\Column(nullable: true)]
    private ?int $seed = null;

    /**
     * The blader, once somebody can say who that is.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true)]
    private ?Player $player = null;

    /** Where they finished *this stage*, not the event. */
    #[ORM\Column(nullable: true)]
    private ?int $stageRank = null;

    /** Challonge's own badge: the entrants who went through to the cut. */
    #[ORM\Column]
    private bool $advanced = false;

    #[ORM\Column(nullable: true)]
    private ?int $wins = null;

    #[ORM\Column(nullable: true)]
    private ?int $losses = null;

    #[ORM\Column(nullable: true)]
    private ?int $ties = null;

    #[ORM\Column(nullable: true)]
    private ?int $byes = null;

    #[ORM\Column(nullable: true)]
    private ?float $score = null;

    #[ORM\Column(nullable: true)]
    private ?float $buchholz = null;

    #[ORM\Column(nullable: true)]
    private ?float $tieBreak = null;

    #[ORM\Column(nullable: true)]
    private ?int $points = null;

    #[ORM\Column(nullable: true)]
    private ?int $pointsDifferential = null;

    public function __construct(
        TournamentStage $stage,
        int $challongeId,
        string $name,
    ) {
        $this->stage = $stage;
        $this->challongeId = $challongeId;
        $this->name = $name;

        $stage->addParticipant($this);
    }

    /**
     * Everything the bracket said about them, as it says it now.
     *
     * The name is in here rather than fixed at construction because a bracket
     * can be edited after an import — a misspelled entrant corrected a week
     * later — and re-archiving is how that correction reaches us.
     */
    public function transcribe(
        string $name,
        ?string $challongeUser,
        ?int $seed,
        ?int $stageRank,
        bool $advanced,
        ChallongeRecord $record,
    ): void {
        $this->name = $name;
        $this->challongeUser = $challongeUser;
        $this->seed = $seed;
        $this->stageRank = $stageRank;
        $this->advanced = $advanced;
        $this->wins = $record->wins;
        $this->losses = $record->losses;
        $this->ties = $record->ties;
        $this->byes = $record->byes;
        $this->score = $record->score;
        $this->buchholz = $record->buchholz;
        $this->tieBreak = $record->tieBreak;
        $this->points = $record->points;
        $this->pointsDifferential = $record->pointsDifferential;
    }

    /**
     * Says who this entrant is, or that nobody knows yet.
     */
    public function isBlader(?Player $player): void
    {
        $this->player = $player;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStage(): TournamentStage
    {
        return $this->stage;
    }

    public function getChallongeId(): int
    {
        return $this->challongeId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getChallongeUser(): ?string
    {
        return $this->challongeUser;
    }

    public function getSeed(): ?int
    {
        return $this->seed;
    }

    public function getPlayer(): ?Player
    {
        return $this->player;
    }

    public function getStageRank(): ?int
    {
        return $this->stageRank;
    }

    public function hasAdvanced(): bool
    {
        return $this->advanced;
    }

    public function getWins(): ?int
    {
        return $this->wins;
    }

    public function getLosses(): ?int
    {
        return $this->losses;
    }

    public function getTies(): ?int
    {
        return $this->ties;
    }

    public function getByes(): ?int
    {
        return $this->byes;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function getBuchholz(): ?float
    {
        return $this->buchholz;
    }

    public function getTieBreak(): ?float
    {
        return $this->tieBreak;
    }

    public function getPoints(): ?int
    {
        return $this->points;
    }

    public function getPointsDifferential(): ?int
    {
        return $this->pointsDifferential;
    }

    public function getRecord(): ChallongeRecord
    {
        return new ChallongeRecord(
            wins: $this->wins,
            losses: $this->losses,
            ties: $this->ties,
            byes: $this->byes,
            score: $this->score,
            buchholz: $this->buchholz,
            tieBreak: $this->tieBreak,
            points: $this->points,
            pointsDifferential: $this->pointsDifferential,
        );
    }
}
