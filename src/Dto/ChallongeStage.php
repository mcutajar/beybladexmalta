<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One stage of a bracket: its rounds, who played in it, every match, and the
 * standings table it produced.
 *
 * Challonge nests this three different ways depending on the format. Here they
 * are one list, in the order they were played.
 */
final class ChallongeStage
{
    /**
     * @param list<ChallongeRound>       $rounds
     * @param list<ChallongeParticipant> $participants
     * @param list<ChallongeMatch>       $matches
     * @param list<ChallongeStanding>    $standings
     */
    public function __construct(
        public readonly ChallongeStageKind $kind,
        public readonly ?string $name,
        public readonly string $format,
        public readonly array $rounds,
        public readonly array $participants,
        public readonly array $matches,
        public readonly array $standings,
    ) {
    }

    public function participant(int $id): ?ChallongeParticipant
    {
        foreach ($this->participants as $participant) {
            if ($participant->id === $id) {
                return $participant;
            }
        }

        return null;
    }

    public function match(int $id): ?ChallongeMatch
    {
        foreach ($this->matches as $match) {
            if ($match->id === $id) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @return list<ChallongeMatch>
     */
    public function playedMatches(): array
    {
        return array_values(array_filter(
            $this->matches,
            static fn (ChallongeMatch $match): bool => $match->wasPlayed(),
        ));
    }

    /**
     * The match that ended the stage — in a cut, the one that decided it.
     *
     * The third-place playoff is played after the final and would otherwise be
     * the last match in the list, which is exactly the mistake this exists to
     * prevent, so consolation matches are left out. Within the last round the
     * highest id wins, which only matters for a stage that ends with more than
     * one match and has no bearing on a bracket that ends in a final.
     */
    public function decidingMatch(): ?ChallongeMatch
    {
        $deciding = null;

        foreach ($this->playedMatches() as $match) {
            if ($match->consolation) {
                continue;
            }

            if (null === $deciding || [$match->round, $match->id] > [$deciding->round, $deciding->id]) {
                $deciding = $match;
            }
        }

        return $deciding;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'name' => $this->name,
            'format' => $this->format,
            'rounds' => array_map(static fn (ChallongeRound $round): array => $round->toArray(), $this->rounds),
            'participants' => array_map(static fn (ChallongeParticipant $participant): array => $participant->toArray(), $this->participants),
            'matches' => array_map(static fn (ChallongeMatch $match): array => $match->toArray(), $this->matches),
            'standings' => array_map(static fn (ChallongeStanding $standing): array => $standing->toArray(), $this->standings),
        ];
    }
}
