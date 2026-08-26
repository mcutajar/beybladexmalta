<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * One match of one bracket: who played, what the scoreline was, and whether
 * anybody actually spun.
 *
 * The tournament is stored beside the stage, and the pair `(tournament,
 * challonge_id)` is the unique key. That is the idempotency this table is
 * built around: re-archiving a bracket has to repair the row rather than write
 * a second one, because `app:import-tournament` has no such guard and a second
 * replay of `repeat.sh` doubles every result it ever wrote. The key is the
 * tournament rather than the stage because a Challonge id is unique across a
 * bracket, so a match cannot silently move between stages and become two.
 *
 * Three different things mean "didn't play", and all three are kept even
 * though nothing renders them yet:
 *
 * - `forfeited` — the match was awarded. Four in the corpus, three of them in
 *   one bracket, each with an empty scoreline.
 * - a `byes` count on `TournamentParticipant`, from the standings column that
 *   only appears in the brackets that had any.
 * - an entrant literally named `bye`, seated in the bracket. There is exactly
 *   one in the corpus and it is in a team event, so nothing archives it today
 *   — but a solo bracket with one would land here as a participant like any
 *   other, because a transcription does not tidy.
 *
 * `consolation` is the third-place playoff. It is played after the final and
 * would otherwise look like it, which is how the knockout winner would end up
 * being the wrong person.
 */
#[ORM\Entity]
#[ORM\Table(name: 'tournament_matches')]
#[ORM\UniqueConstraint(name: 'uniq_tournament_match', columns: ['tournament_id', 'challonge_id'])]
class TournamentMatch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Tournament $tournament;

    #[ORM\ManyToOne(inversedBy: 'matches')]
    #[ORM\JoinColumn(name: 'stage_id', nullable: false)]
    private TournamentStage $stage;

    #[ORM\Column]
    private int $challongeId;

    #[ORM\Column]
    private int $round = 0;

    /** Challonge's label for the match within its round — "A", "B", "C". */
    #[ORM\Column(length: 16, nullable: true)]
    private ?string $identifier = null;

    /** `complete`, `open`, `pending`. */
    #[ORM\Column(length: 32)]
    private string $state;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'player1_id', nullable: true)]
    private ?TournamentParticipant $player1 = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'player2_id', nullable: true)]
    private ?TournamentParticipant $player2 = null;

    #[ORM\Column(nullable: true)]
    private ?int $player1Score = null;

    #[ORM\Column(nullable: true)]
    private ?int $player2Score = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'winner_id', nullable: true)]
    private ?TournamentParticipant $winner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'loser_id', nullable: true)]
    private ?TournamentParticipant $loser = null;

    #[ORM\Column]
    private bool $forfeited = false;

    #[ORM\Column]
    private bool $consolation = false;

    /** @var Collection<int, MatchGame> */
    #[ORM\OneToMany(targetEntity: MatchGame::class, mappedBy: 'match', cascade: ['persist'], orphanRemoval: true)]
    private Collection $games;

    public function __construct(
        TournamentStage $stage,
        int $challongeId,
    ) {
        $this->stage = $stage;
        $this->tournament = $stage->getTournament();
        $this->challongeId = $challongeId;
        $this->state = '';
        $this->games = new ArrayCollection();

        $stage->addMatch($this);
    }

    /**
     * Moves the match to the stage the bracket now puts it in.
     *
     * Reached only when a bracket has been restructured upstream since it was
     * archived. It is a move rather than a delete and an insert because the
     * Challonge id is half the unique key: writing the new row before dropping
     * the old one is exactly the collision that key exists to cause — Doctrine
     * runs its inserts before its deletes.
     *
     * Leaving the old stage's collection is safe only because that collection
     * does not orphan-remove; `TournamentStage::$matches` records why, and the
     * stage the match is going to may well be one built moments ago.
     */
    public function belongsTo(TournamentStage $stage): void
    {
        if ($this->stage === $stage) {
            return;
        }

        $this->stage->removeMatch($this);
        $this->stage = $stage;
        $this->tournament = $stage->getTournament();

        $stage->addMatch($this);
    }

    public function transcribe(
        int $round,
        ?string $identifier,
        string $state,
        bool $forfeited,
        bool $consolation,
    ): void {
        $this->round = $round;
        $this->identifier = $identifier;
        $this->state = $state;
        $this->forfeited = $forfeited;
        $this->consolation = $consolation;
    }

    public function between(
        ?TournamentParticipant $player1,
        ?TournamentParticipant $player2,
    ): void {
        $this->player1 = $player1;
        $this->player2 = $player2;
    }

    /**
     * The result Challonge shows, which for a multi-game match is games won
     * rather than points. A forfeit has none.
     */
    public function scored(?int $player1Score, ?int $player2Score): void
    {
        $this->player1Score = $player1Score;
        $this->player2Score = $player2Score;
    }

    public function decided(
        ?TournamentParticipant $winner,
        ?TournamentParticipant $loser,
    ): void {
        $this->winner = $winner;
        $this->loser = $loser;
    }

    /**
     * Writes the per-game scorelines, and **only when there is more than one**.
     *
     * The rule lives here rather than in the archive service so that every
     * path that ever writes a match inherits it instead of having to remember
     * it — the same reason the smoke check sits inside the fetcher. A single
     * game says nothing its own match does not already say, so it is dropped;
     * a match that has *become* single-game since the last archive loses the
     * rows it had.
     *
     * @param list<list<int>> $games every game played, in order, each `[player1, player2]`
     */
    public function transcribeGames(array $games): void
    {
        if (count($games) < 2) {
            $this->games->clear();

            return;
        }

        $number = 0;

        foreach ($games as $game) {
            ++$number;

            $player1Score = $game[0] ?? 0;
            $player2Score = $game[1] ?? 0;
            $existing = $this->game($number);

            if (null === $existing) {
                $this->games->add(new MatchGame($this, $number, $player1Score, $player2Score));

                continue;
            }

            $existing->scored($player1Score, $player2Score);
        }

        foreach ($this->games as $game) {
            if ($game->getNumber() > $number) {
                $this->games->removeElement($game);
            }
        }
    }

    public function game(int $number): ?MatchGame
    {
        foreach ($this->games as $game) {
            if ($game->getNumber() === $number) {
                return $game;
            }
        }

        return null;
    }

    /**
     * Whether the match was actually contested, on the same terms the
     * snapshot uses: complete, and not awarded.
     */
    public function wasPlayed(): bool
    {
        return 'complete' === $this->state && !$this->forfeited;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTournament(): Tournament
    {
        return $this->tournament;
    }

    public function getStage(): TournamentStage
    {
        return $this->stage;
    }

    public function getChallongeId(): int
    {
        return $this->challongeId;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function getIdentifier(): ?string
    {
        return $this->identifier;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function getPlayer1(): ?TournamentParticipant
    {
        return $this->player1;
    }

    public function getPlayer2(): ?TournamentParticipant
    {
        return $this->player2;
    }

    public function getPlayer1Score(): ?int
    {
        return $this->player1Score;
    }

    public function getPlayer2Score(): ?int
    {
        return $this->player2Score;
    }

    public function getWinner(): ?TournamentParticipant
    {
        return $this->winner;
    }

    public function getLoser(): ?TournamentParticipant
    {
        return $this->loser;
    }

    public function isForfeited(): bool
    {
        return $this->forfeited;
    }

    public function isConsolation(): bool
    {
        return $this->consolation;
    }

    /** @return Collection<int, MatchGame> */
    public function getGames(): Collection
    {
        return $this->games;
    }
}
