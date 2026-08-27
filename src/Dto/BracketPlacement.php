<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One line of the finishing order the preview proposes.
 *
 * Both names are kept and both are shown, because they disagree often enough
 * to matter: the bracket says `Guzman`, the league says `Guzman93`, and the
 * whole point of the screen is that somebody can see which is which before
 * anything is written. The phone drops the Challonge column, not this one.
 *
 * `position` is the rank the league will award, and it is the row's place in
 * the list rather than the rank Challonge gave. The two come apart as soon as
 * an entrant is dropped — a `bye` at rank 12 of one captured bracket, an
 * entrant somebody marks as not a person — and it is the league's rank that
 * the F1 matrix pays out on. Neither is editable: the bracket's finishing
 * order has been right eighteen times out of eighteen, and a screen that
 * offered to overrule it would be offering to get it wrong.
 */
final readonly class BracketPlacement
{
    public function __construct(
        public int $position,
        public int $challongeRank,
        public string $challongeName,
        public ?string $bladerName,
        public bool $isNewBlader,
        public int $f1Points,
        public int $bonusPoints,
        public bool $wonTheKnockout,
    ) {
    }

    /**
     * Whether the league pays anything for this finish.
     *
     * The matrix stops at ten. Everyone below it is archived and unscored,
     * which is half the matches in the corpus and no points at all.
     */
    public function scores(): bool
    {
        return $this->f1Points > 0 || $this->bonusPoints > 0;
    }

    public function totalPoints(): int
    {
        return $this->f1Points + $this->bonusPoints;
    }
}
