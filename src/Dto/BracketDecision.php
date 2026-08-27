<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * One display name off a bracket that nobody can read for you.
 *
 * The epic's one hard problem, in the shape a form can render: the spelling
 * Challonge used, what is known about the entrant it belongs to, and the three
 * or four bladers worth considering.
 *
 * **A question with nothing close to it is already answered: somebody new.**
 * That default is here rather than in the previewer or the template, because
 * it is a fact about the question rather than about how it is rendered, and
 * because `wasSeeded()` has to be the same rule read backwards.
 *
 * **A question with a suggestion is never answered on the blader's behalf.**
 * The two directions are not the same risk. An unnecessary blader is a
 * duplicate row: visible in the list, and #56 merges it away. An unnecessary
 * alias welds two people into one, and there is no unmerge — nothing on any
 * page looks wrong afterwards. Measured on the 23 August bracket, one
 * suggestion in four was that mistake waiting to happen: `Steve V.` is one
 * edit from `Steve` and is a different person.
 *
 * So the suggestion is offered first, loudest and one tap away, and it is
 * still a tap.
 *
 * A **collision** is the same question pointed the other way, and it is the
 * one with no answer on this screen at all. The spelling reaches more than one
 * blader already, so no alias can settle it: `AliasService` refuses to file
 * one onto a blader's own name, and picking a side would split somebody's
 * career in half without saying so. Two rows for one person is the merge in
 * #56.
 */
final readonly class BracketDecision
{
    /**
     * What this screen proposes when nobody has answered.
     */
    public string $answer;

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
        string $answer = '',
    ) {
        $this->answer = '' === $answer && $this->hasNothingClose()
            ? BracketAnswers::CREATE
            : $answer;
    }

    /**
     * Whether the answer this carries is the one the screen proposed rather
     * than one somebody picked.
     *
     * Derived rather than transported. The screen renders a seeded row with
     * its default already selected, so the browser posts it back like any
     * other answer and a flag saying "this was not looked at" would be a flag
     * the browser controls. What this states is narrower and true either way:
     * **the answer is the default, and it was not changed.** That is what you
     * need when a duplicate blader turns up three brackets later and the
     * question is whether anybody ever examined the row.
     */
    public function wasSeeded(): bool
    {
        return $this->hasNothingClose() && BracketAnswers::CREATE === $this->answer;
    }

    /**
     * Whether this one still stops the import.
     *
     * A collision always does. A question with a suggestion does until it is
     * answered. A question with nothing close never does, because it arrives
     * answered.
     */
    public function isOutstanding(): bool
    {
        return $this->isCollision || '' === $this->answer;
    }

    /**
     * The blader the screen puts first, when there is one worth putting there.
     */
    public function best(): ?AliasSuggestion
    {
        return $this->suggestions[0] ?? null;
    }

    /**
     * The candidates behind the "someone else" control.
     *
     * @return list<AliasSuggestion>
     */
    public function otherCandidates(): array
    {
        return array_slice($this->suggestions, 1);
    }

    /**
     * Whether the answer points at somebody the shortlist did not offer, which
     * is what keeps the "someone else" control open across a re-render.
     */
    public function reachesPastTheShortlist(): bool
    {
        $id = BracketAnswers::bladerId($this->answer);

        if (null === $id) {
            return false;
        }

        foreach ($this->suggestions as $suggestion) {
            if ($suggestion->player->getId() === $id) {
                return false;
            }
        }

        return true;
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

    /**
     * Whether the league offered nothing for this spelling at all.
     *
     * The question that arrives answered, and — separately from what the
     * answer currently is — the one the screen renders as a picker rather than
     * as buttons. Those have to be the same test: a settled row somebody
     * changes to a blader is still a settled row, and flipping its shape on
     * re-render would hide the choice they had just made.
     *
     * A collision is never seeded and never settled; it has no answer to give.
     */
    public function hasNothingClose(): bool
    {
        return !$this->isCollision && [] === $this->suggestions;
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
