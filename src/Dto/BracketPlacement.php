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
        public ChallongeRecord $record = new ChallongeRecord(),
    ) {
    }

    /**
     * The W-L-D the standings row printed, as three integers.
     *
     * Read here rather than persisted, because the preview writes nothing and
     * the archive has not been transcribed yet — but an unranked event's
     * finishing order is the only table on its screen, and a rank with no
     * record beside it says very little about who was there. A column the
     * bracket did not print reads as absent rather than as zero, which is why
     * `matches()` can be zero for a cut's standings.
     */
    public function matches(): int
    {
        return ($this->record->wins ?? 0) + ($this->record->losses ?? 0) + ($this->record->ties ?? 0);
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
