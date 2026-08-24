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
