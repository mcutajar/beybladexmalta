<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Player;

/**
 * A blader the unresolved name might be, and the reason for thinking so.
 *
 * Offered and never applied. Nothing in the codebase may take one of these and
 * write it to the alias table on its own: the whole point of returning a name
 * as a question is that the answer comes from somebody who was at the event.
 */
final class AliasSuggestion
{
    public function __construct(
        public readonly Player $player,
        /** The known spelling that brought them up, normalised. */
        public readonly string $spelling,
        public readonly AliasSuggestionReason $reason,
        /** Edits between the two normalised spellings; 0 where they are equal. */
        public readonly int $distance,
    ) {
    }

    /**
     * The reason in words, for a console table or a preview screen.
     */
    public function because(): string
    {
        return match ($this->reason) {
            AliasSuggestionReason::ChallongeAccount => sprintf('the bracket rendered the Challonge account "%s"', $this->spelling),
            AliasSuggestionReason::SpelledTheSameWay => sprintf('is already spelled "%s"', $this->spelling),
            AliasSuggestionReason::Spelling => sprintf('%d %s from "%s"', $this->distance, 1 === $this->distance ? 'edit' : 'edits', $this->spelling),
            AliasSuggestionReason::PartOfAKnownName => sprintf('shares a stem with "%s"', $this->spelling),
        };
    }
}
