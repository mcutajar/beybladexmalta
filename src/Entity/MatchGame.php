<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * One game inside a match, written only when a match had more than one.
 *
 * Single-game matches keep their score on TournamentMatch without duplicating
 * it here. Solo multi-set events preserve each set's points in these rows,
 * while the match itself records sets won. Team sets remain in the snapshot;
 * team events do not archive blader-level matches.
 *
 * TournamentMatch::transcribeGames() owns that distinction.
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
