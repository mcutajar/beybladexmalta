<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ChallongeJoin;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongePlacing;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStanding;

/**
 * Joins each standings row to the entrant it is about.
 *
 * This is harder than it sounds, and a name join is the wrong answer. A
 * standings row does not reliably carry the participant's name: a blader who
 * linked their Challonge account is rendered as that account instead, so the
 * row says `Sanya0207` where every match in the same bracket says `legion`.
 * Matching those two strings is not possible and never will be.
 *
 * What every row does carry is its match history, and a match names its two
 * players. Intersect the players of every match in the row and exactly one
 * entrant survives — the only person who was in all of them. That is a fact
 * about who played what rather than a guess about spelling, and it settles
 * 377 of the 482 rows across the captured brackets.
 *
 * The name is the fallback, and it is needed twice. A row with a single match
 * narrows to two people rather than one, which is every entrant knocked out in
 * the first round of a cut. And the standings table of a one-stage bracket
 * carries no match history at all, so every row in one falls back. The
 * comparison is deliberately meagre — the same string, ignoring case, against
 * either the row's name or the account rendered in its place. Anything cleverer
 * would be aliasing, and aliasing is a stored table a person curates, not a
 * heuristic hidden in a parser.
 */
class ChallongeStandingsResolver
{
    /**
     * The finishing order of the event: the ranking stage's standings, joined,
     * in the order Challonge ranked them.
     *
     * @return list<ChallongePlacing>
     */
    public function finishingOrder(ChallongeSnapshot $snapshot): array
    {
        $stage = $snapshot->rankingStage();

        return null === $stage ? [] : $this->resolve($stage);
    }

    /**
     * @return list<ChallongePlacing>
     */
    public function resolve(ChallongeStage $stage): array
    {
        return array_map(
            fn (ChallongeStanding $standing): ChallongePlacing => $this->placing($standing, $stage),
            $stage->standings,
        );
    }

    private function placing(ChallongeStanding $standing, ChallongeStage $stage): ChallongePlacing
    {
        $candidates = $this->playedEveryMatch($standing, $stage);

        if (1 === count($candidates)) {
            $participant = $stage->participant($candidates[0]);

            if (null !== $participant) {
                return new ChallongePlacing($standing, $participant, ChallongeJoin::MatchIds);
            }
        }

        $named = $this->named($standing, $stage);

        /*
         * When the intersection narrowed the field without settling it, the
         * name only has to pick between the people who were actually there.
         * A name that matches nobody in that set is more likely a rendering
         * we have not seen than a row about someone else, so the wider match
         * is kept rather than discarded.
         */
        $inBoth = array_values(array_filter(
            $named,
            static fn (ChallongeParticipant $participant): bool => in_array($participant->id, $candidates, true),
        ));

        if ([] !== $inBoth) {
            $named = $inBoth;
        }

        if (1 === count($named)) {
            return new ChallongePlacing($standing, $named[0], ChallongeJoin::Name);
        }

        return new ChallongePlacing($standing, null, ChallongeJoin::None);
    }

    /**
     * The entrants who played every match in the row's match-history cell.
     *
     * A match the stage does not hold is skipped rather than treated as
     * nobody: the group and final stages have disjoint id spaces, and a cell
     * that pointed somewhere else would otherwise empty the intersection and
     * take the row's real answer with it.
     *
     * @return list<int>
     */
    private function playedEveryMatch(ChallongeStanding $standing, ChallongeStage $stage): array
    {
        $candidates = null;

        foreach ($standing->matchIds as $matchId) {
            $match = $stage->match($matchId);

            if (null === $match) {
                continue;
            }

            $players = array_values(array_filter([$match->player1Id, $match->player2Id], is_int(...)));

            $candidates = null === $candidates
                ? $players
                : array_values(array_intersect($candidates, $players));
        }

        return $candidates ?? [];
    }

    /**
     * @return list<ChallongeParticipant>
     */
    private function named(ChallongeStanding $standing, ChallongeStage $stage): array
    {
        $names = array_filter([
            $this->fold($standing->name),
            $this->fold($standing->challongeUser),
        ]);

        if ([] === $names) {
            return [];
        }

        return array_values(array_filter(
            $stage->participants,
            fn (ChallongeParticipant $participant): bool => in_array($this->fold($participant->name), $names, true),
        ));
    }

    private function fold(?string $name): string
    {
        return mb_strtolower(trim($name ?? ''));
    }
}
