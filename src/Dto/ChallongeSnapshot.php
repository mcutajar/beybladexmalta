<?php

declare(strict_types=1);

namespace App\Dto;

use App\Exception\UnsupportedChallongeBracketException;

/**
 * One bracket as Challonge served it, at one moment, with the noise removed.
 *
 * This is the whole point of the fetch step: everything downstream reads the
 * snapshot, never the network, so `repeat.sh` replays offline and a bracket
 * that is later edited or deleted cannot change history.
 *
 * What it keeps is every fact Challonge stated — the matches with their
 * per-game scorelines, the entrants, the standings tables column for column.
 * What it drops is what the embed needs and we never will: portrait URLs,
 * chat and station flags, checksums, and the fields that are null in every
 * match of every bracket. What it deliberately does *not* do is decide
 * anything: no column is renamed into our vocabulary and no display name is
 * resolved to one of our players, because both of those change and the file
 * cannot.
 */
final class ChallongeSnapshot
{
    /**
     * Bumped when the shape of the file changes, so a reader can tell.
     */
    public const VERSION = 1;

    /**
     * @param list<ChallongeStage> $stages in the order they were played
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $sourceUrl,
        public readonly \DateTimeImmutable $fetchedAt,
        public readonly int $tournamentId,
        public readonly string $tournamentType,
        public readonly string $tournamentState,
        public readonly bool $isTeamTournament,
        public readonly array $stages,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'slug' => $this->slug,
            'source_url' => $this->sourceUrl,
            'fetched_at' => $this->fetchedAt->format(\DATE_ATOM),
            'tournament' => [
                'id' => $this->tournamentId,
                'type' => $this->tournamentType,
                'state' => $this->tournamentState,
                'is_team' => $this->isTeamTournament,
            ],
            'stages' => array_map(static fn (ChallongeStage $stage): array => $stage->toArray(), $this->stages),
        ];
    }

    /**
     * The stage whose standings order the event.
     *
     * That is the Swiss or round-robin stage everybody played: the group stage
     * when there is a cut, and the whole tournament when there is not. It is
     * never the cut itself — eight people play that, and the finishing order
     * of an event is the order of the stage all of them were in.
     *
     * Which makes this the first stage, and that holds only because **a
     * bracket here has at most one group**. Every captured bracket is either a
     * single stage or exactly a group and a final. A pools event would be
     * `[pool A, pool B, final]`, and answering with pool A would be a
     * finishing order for a third of the entrants presented as one for all of
     * them — wrong, and with nothing out of place to notice. So it refuses
     * instead: the league does not run pools, and the day it does, this is the
     * decision that has to be made rather than inherited.
     */
    public function rankingStage(): ?ChallongeStage
    {
        $groups = array_filter(
            $this->stages,
            static fn (ChallongeStage $stage): bool => ChallongeStageKind::Group === $stage->kind,
        );

        if (count($groups) > 1) {
            throw new UnsupportedChallongeBracketException(sprintf('The bracket "%s" has %d group stages, and there is no rule yet for how pools combine into one finishing order.', $this->slug, count($groups)));
        }

        return $this->stages[0] ?? null;
    }

    /**
     * The top cut, if the bracket had one.
     */
    public function cutStage(): ?ChallongeStage
    {
        foreach ($this->stages as $stage) {
            if (ChallongeStageKind::Final === $stage->kind) {
                return $stage;
            }
        }

        return null;
    }

    /**
     * Who won the cut — the winner of the last match played in it, with the
     * third-place playoff excluded because it is played afterwards.
     *
     * Null when the bracket had no cut, or had one that was never finished.
     */
    public function knockoutWinner(): ?ChallongeParticipant
    {
        $cut = $this->cutStage();
        $deciding = $cut?->decidingMatch();

        if (null === $cut || null === $deciding || null === $deciding->winnerId) {
            return null;
        }

        return $cut->participant($deciding->winnerId);
    }

    public function matchCount(): int
    {
        return array_sum(array_map(
            static fn (ChallongeStage $stage): int => count($stage->matches),
            $this->stages,
        ));
    }

    /**
     * How many of those matches were actually contested.
     *
     * Two things are left out and only one of them is a forfeit: the corpus
     * holds 4 forfeits and 8 matches that were never played at all, the cut of
     * a 2v2 bracket nobody finished.
     */
    public function playedMatchCount(): int
    {
        return array_sum(array_map(
            static fn (ChallongeStage $stage): int => count($stage->playedMatches()),
            $this->stages,
        ));
    }

    /**
     * A bracket renders no standings at all unless `show_standings=1` was
     * sent, so an empty table is worth saying out loud rather than storing.
     */
    public function hasStandings(): bool
    {
        foreach ($this->stages as $stage) {
            if ([] !== $stage->standings) {
                return true;
            }
        }

        return false;
    }
}
