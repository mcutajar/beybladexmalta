<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\TournamentMatch;
use App\Entity\TournamentStage;

/**
 * What to call a round, in Challonge's own vocabulary.
 *
 * A cut round has no name of its own in the bracket — it is a number, and
 * whether number three means "quarter-final" depends on how many rounds the
 * cut had. Two pages now ask that question: the tournament page names the
 * columns of its scoreboard, and a blader's career log names the round each of
 * their matches was. The rule lives here so the second page cannot drift from
 * the first, which is the whole reason the cut's third-place playoff is
 * labelled rather than left looking like the final.
 */
final class BracketRoundLabels
{
    /**
     * Challonge's own word for a knockout stage.
     *
     * The branch is on the format rather than on `ChallongeStageKind`,
     * because a one-stage event that *is* a knockout should read as one. The
     * kind only says whether a stage has a sibling.
     */
    private const string SINGLE_ELIMINATION = 'single elimination';

    public function isCut(TournamentStage $stage): bool
    {
        return self::SINGLE_ELIMINATION === $stage->getFormat();
    }

    /**
     * The heading a reader would say out loud — "Semi-finals".
     */
    public function long(TournamentStage $stage, TournamentMatch $match): string
    {
        if ($match->isConsolation()) {
            return 'Third-place playoff';
        }

        return match ($stage->getRounds() - $match->getRound()) {
            0 => 'Final',
            1 => 'Semi-finals',
            2 => 3 === $stage->getRounds() ? 'Quarter-finals' : sprintf('Round %d', $match->getRound()),
            default => sprintf('Round %d', $match->getRound()),
        };
    }

    /**
     * The same thing in the width a table cell has — "SF".
     */
    public function short(TournamentStage $stage, TournamentMatch $match): string
    {
        if ($match->isConsolation()) {
            return '3P';
        }

        return match ($stage->getRounds() - $match->getRound()) {
            0 => 'F',
            1 => 'SF',
            2 => 3 === $stage->getRounds() ? 'QF' : sprintf('R%d', $match->getRound()),
            default => sprintf('R%d', $match->getRound()),
        };
    }

    /**
     * The column a cut scoreboard files the match under.
     *
     * The final and the playoff for third are played after everything else and
     * share a column, because a blader reaches exactly one of them.
     */
    public function column(TournamentStage $stage, TournamentMatch $match): string
    {
        $label = $this->short($stage, $match);

        return in_array($label, ['F', '3P'], true) ? 'F/3P' : $label;
    }

    /**
     * The short label for a match in *any* stage.
     *
     * Everything above is about a knockout. The rounds everybody plays are
     * numbered and mean nothing more than their number, so a Swiss or
     * round-robin match is "R3" and only a cut needs interpreting.
     */
    public function inStage(TournamentStage $stage, TournamentMatch $match): string
    {
        if ($this->isCut($stage)) {
            return $this->short($stage, $match);
        }

        return sprintf('R%d', $match->getRound());
    }

    /**
     * What to call a stage that is not a cut, in Challonge's own vocabulary.
     *
     * The league has played Swiss every week and one round robin, and a
     * heading reading "Swiss" over a round robin would be the page stating
     * something the bracket never said.
     */
    public function stage(TournamentStage $stage): string
    {
        return match ($stage->getFormat()) {
            'swiss' => 'Swiss',
            'round robin' => 'Round-robin',
            default => ucfirst($stage->getFormat()),
        };
    }
}
