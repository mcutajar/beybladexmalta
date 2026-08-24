<?php

declare(strict_types=1);

namespace App\Dto;

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
     */
    public function rankingStage(): ?ChallongeStage
    {
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
     * How many of those matches were contested rather than forfeited.
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
