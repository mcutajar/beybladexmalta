<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;

/**
 * The one place that reads a side out of an archived match.
 *
 * Extracted from `PlayerCareerPresenter`, which said in its own docblock that
 * #59's records board would need the same aggregation. It does — and the three
 * counting rules settled on #58 are rules about the whole archive rather than
 * about one profile, so they are stated once here and applied by both:
 *
 * - **A forfeit is a win and a loss, and no points either way.** Four matches
 *   in the corpus were awarded rather than played and carry no scoreline at
 *   all. Counting them at 0-0 would quietly drag every rate down, and on the
 *   records board it would also invent shutouts nobody bladed.
 * - **A third-place playoff is a match like any other.** Sixteen of them.
 * - **A draw is a third outcome, never half a loss.** There is exactly one.
 *   `TournamentMatch::outcomeFor()` owns that reading; nothing here second-
 *   guesses it.
 */
final class ArchivedMatchReader
{
    /**
     * Which of the match's two entrants is this blader.
     *
     * Not cached across matches: the group stage and the cut number their
     * entrants in unrelated spaces, so a blader who made the cut is a
     * different `TournamentParticipant` in the second half of their own
     * evening.
     */
    public function sideOf(Player $player, TournamentMatch $match): ?TournamentParticipant
    {
        foreach ([$match->getPlayer1(), $match->getPlayer2()] as $side) {
            if (null !== $side && $side->getPlayer() === $player) {
                return $side;
            }
        }

        return null;
    }

    public function opponentOf(TournamentMatch $match, TournamentParticipant $side): ?TournamentParticipant
    {
        return $match->getPlayer1() === $side ? $match->getPlayer2() : $match->getPlayer1();
    }

    /**
     * An awarded match has no scoreline, so it reports none.
     *
     * The guard is on the flag rather than on the values, because a forfeit
     * that ever arrives carrying a 0-0 is still a match nobody bladed.
     */
    public function scoreFor(TournamentMatch $match, TournamentParticipant $side): ?int
    {
        if ($match->isForfeited()) {
            return null;
        }

        return $match->getPlayer1() === $side ? $match->getPlayer1Score() : $match->getPlayer2Score();
    }

    public function scoreAgainst(TournamentMatch $match, TournamentParticipant $side): ?int
    {
        if ($match->isForfeited()) {
            return null;
        }

        return $match->getPlayer1() === $side ? $match->getPlayer2Score() : $match->getPlayer1Score();
    }

    /**
     * The name to print for an entrant nobody has resolved to a blader.
     *
     * Every entrant in the replayed data does resolve, so this is reached only
     * by an archive written without going through the import screen. It still
     * has to say something rather than print an empty cell.
     */
    public function nameOf(?TournamentParticipant $participant): string
    {
        if (null === $participant) {
            return 'Bye';
        }

        return $participant->getPlayer()?->getName()
            ?? str_replace(' (invitation pending)', '', $participant->getName());
    }
}
