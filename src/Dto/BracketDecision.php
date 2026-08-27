<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One display name off a bracket that nobody can read for you.
 *
 * The epic's one hard problem, in the shape a form can render: the spelling
 * Challonge used, what is known about the entrant it belongs to, and the three
 * or four bladers worth considering. Nothing here is a decision already taken
 * — `suggestions` is offered and never applied, which is what makes the screen
 * worth looking at.
 *
 * A **collision** is the same question pointed the other way, and it is the
 * one with no answer on this screen. The spelling reaches more than one blader
 * already, so no alias can settle it: `AliasService` refuses to file one onto
 * a blader's own name, and picking a side would split somebody's career in
 * half without saying so. Two rows for one person is the merge in #56.
 */
final readonly class BracketDecision
{
    /**
     * @param string                $key         the field name this is answered under, which is the
     *                                           normalised spelling — two entrants spelled the same way
     *                                           in the group stage and the cut are one decision
     * @param string                $name        as the bracket spelled it
     * @param list<AliasSuggestion> $suggestions best first; empty when nothing came close
     * @param ?int                  $rank        where they finished, when the standings say so
     * @param int                   $matches     how many matches they played, across every stage
     * @param string                $answer      what has been chosen so far, echoed back so a re-render
     *                                           does not lose it
     */
    public function __construct(
        public string $key,
        public string $name,
        public bool $isCollision,
        public string $problem,
        public array $suggestions,
        public ?int $rank,
        public int $matches,
        public string $answer = '',
    ) {
    }

    /**
     * Whether this one still stops the import.
     *
     * A collision always does. Anything else is settled the moment it is
     * answered, and the three answers are equally valid — linking to a blader,
     * creating one, and saying the entrant is not a person.
     */
    public function isOutstanding(): bool
    {
        return $this->isCollision || '' === $this->answer;
    }

    /**
     * Where they finished and how much they played, in one line.
     *
     * The rank is the part that matters most: fifty-two of the unresolved
     * spellings across the captured brackets finished eleventh or worse, which
     * is exactly why nothing could learn them from the placement lists.
     */
    public function context(): string
    {
        $played = sprintf('%d %s', $this->matches, 1 === $this->matches ? 'match' : 'matches');

        return null === $this->rank
            ? sprintf('Not in the standings. %s.', ucfirst($played))
            : sprintf('Finished %s. Played %s.', self::ordinal($this->rank), $played);
    }

    private static function ordinal(int $rank): string
    {
        $suffix = match (true) {
            in_array($rank % 100, [11, 12, 13], true) => 'th',
            1 === $rank % 10 => 'st',
            2 === $rank % 10 => 'nd',
            3 === $rank % 10 => 'rd',
            default => 'th',
        };

        return $rank.$suffix;
    }
}
