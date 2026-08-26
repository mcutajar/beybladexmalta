<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One game inside a match, written only when a match had more than one.
 *
 * The table starts empty and that is the design rather than an accident.
 * Every one of the 947 played solo matches in the captured corpus is a single
 * game, so a row per game would restate its own match's scoreline 947 times
 * and carry nothing new. All fifty-one multi-game matches are team matches —
 * twenty-nine best-of-2 and twenty-two best-of-3 — and a team event archives
 * its entrants and nothing else, because Challonge records only the aggregate
 * of a team match and nothing in it says which half of either team played
 * which game.
 *
 * So the scoreline lives on `TournamentMatch`, and this exists for the first
 * solo best-of-three the league plays. `TournamentMatch::transcribeGames()`
 * owns the rule, so a caller cannot forget it; a backfill that produced 947
 * rows would be the sign it had been bypassed.
 *
 * The number is the game's place in the match, one-based, and carries the
 * unique index with it. There are no setters: a game is transcribed by the
 * archive and re-transcribed by the next one.
 */
#[ORM\Entity]
#[ORM\Table(name: 'match_games')]
#[ORM\UniqueConstraint(name: 'uniq_match_game_number', columns: ['match_id', 'number'])]
class MatchGame
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'games')]
    #[ORM\JoinColumn(name: 'match_id', nullable: false)]
    private TournamentMatch $match;

    #[ORM\Column]
    private int $number;

    #[ORM\Column]
    private int $player1Score;

    #[ORM\Column]
    private int $player2Score;

    public function __construct(
        TournamentMatch $match,
        int $number,
        int $player1Score,
        int $player2Score,
    ) {
        $this->match = $match;
        $this->number = $number;
        $this->player1Score = $player1Score;
        $this->player2Score = $player2Score;
    }

    public function scored(int $player1Score, int $player2Score): void
    {
        $this->player1Score = $player1Score;
        $this->player2Score = $player2Score;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMatch(): TournamentMatch
    {
        return $this->match;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getPlayer1Score(): int
    {
        return $this->player1Score;
    }

    public function getPlayer2Score(): int
    {
        return $this->player2Score;
    }
}
