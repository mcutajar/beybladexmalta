<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Player;

/**
 * One pair the bootstrap pass read out of an event that was already imported.
 *
 * The evidence behind it is worth naming, because it is not a guess: rank *n*
 * of a captured bracket is line *n* of the placement list somebody typed by
 * hand at the time, verified position for position across all sixteen
 * non-team events. So a spelling at rank 5 and the blader recorded at rank 5
 * are the same person, said by the import rather than by this class.
 *
 * `events` is which imports said so. A pair carried by six evenings is a
 * different thing to read in a review table than a pair carried by one, and
 * the whole point of printing the table is that somebody reads it.
 */
final class AliasProposal
{
    /**
     * @param string       $spelling as the bracket spelled it
     * @param list<string> $events   the imports that paired the two, oldest first
     */
    public function __construct(
        public readonly string $spelling,
        public readonly string $normalised,
        public readonly Player $blader,
        public readonly AliasProposalStatus $status,
        public readonly array $events,
    ) {
    }

    public function isWritable(): bool
    {
        return $this->status->isWritable();
    }

    public function bladerName(): string
    {
        return $this->blader->getName();
    }

    /**
     * How many evenings agreed. Not how many placements: two spellings that
     * fold to the same string are one row here, so the count is of events
     * rather than of lines.
     */
    public function timesSeen(): int
    {
        return count($this->events);
    }
}
