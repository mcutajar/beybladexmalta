<?php

declare(strict_types=1);

namespace App\Dto;

/**
 * What somebody said about the names a bracket used that the league could not
 * read.
 *
 * A name has three answers and only the first was ever specified:
 *
 * 1. **an existing blader** — `blader:12`, which files an alias
 * 2. **somebody new** — `create`, which is the only place in the system that
 *    invents a blader, and is never pre-selected
 * 3. **not a person** — `drop`, which is Challonge's own `bye` and nothing
 *    else in the captured corpus
 *
 * Keyed by the normalised spelling rather than by the raw one, so the same
 * entrant appearing in the group stage and the cut is one question with one
 * answer. Nothing is trusted: an answer is looked up by a key the preview
 * produced, a blader id is a number this class only parses, and an answer for
 * a name the bracket does not hold is simply never asked for.
 */
final readonly class BracketAnswers
{
    public const string CREATE = 'create';

    public const string DROP = 'drop';

    /**
     * "The blader picked in the dropdown below", which is what the buttons
     * cannot say on their own.
     *
     * A row's answer has to be one control or one radio group: a `<select>`
     * sharing the field name posts alongside the radios and, being later in
     * the document, quietly wins — blanking a button somebody pressed. Order
     * cannot express "whichever was touched last", so the dropdown is a
     * separate field and this is the radio that hands over to it. The
     * controller folds the two together before anything here sees them, so an
     * answer is still one string.
     */
    public const string ELSEWHERE = 'else';

    private const string BLADER = 'blader:';

    /**
     * @param array<string, string> $answers normalised spelling => the posted value
     */
    public function __construct(private array $answers = [])
    {
    }

    /**
     * Whatever was said about this spelling, or '' when nothing was.
     */
    public function for(string $normalised): string
    {
        $answer = trim($this->answers[$normalised] ?? '');

        return $this->isUnderstood($answer) ? $answer : '';
    }

    public function isEmpty(): bool
    {
        return [] === $this->answers;
    }

    /**
     * The blader an answer points at, when it points at one.
     */
    public static function bladerId(string $answer): ?int
    {
        if (!str_starts_with($answer, self::BLADER)) {
            return null;
        }

        $id = substr($answer, strlen(self::BLADER));

        return ctype_digit($id) ? (int) $id : null;
    }

    /**
     * The value a "this is blader n" option carries.
     */
    public static function linkTo(int $bladerId): string
    {
        return self::BLADER.$bladerId;
    }

    private function isUnderstood(string $answer): bool
    {
        return self::CREATE === $answer
            || self::DROP === $answer
            || null !== self::bladerId($answer);
    }
}
