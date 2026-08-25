<?php

declare(strict_types=1);

namespace App\Dto;

use App\Entity\Player;

/**
 * What the league made of one display name off a bracket.
 *
 * Either a blader, or a question. There is deliberately no third state in
 * which a name that reached nobody quietly becomes a new blader: the league
 * has seventy-six of those and knows all of them, so an unrecognised spelling
 * is a spelling nobody has told us about yet, not a seventy-seventh person.
 * An importer that auto-created would be one typo away from splitting a
 * blader's career in half, and the split is silent — which is why the answer
 * comes back as `suggestions` for a person to choose from rather than as a
 * best guess already acted on.
 *
 * A name that reaches *two* people is the same refusal pointed the other way,
 * and it is the one this class cares most about. It means the league's own
 * records have a collision in them — two blader rows spelled alike, or a
 * blader whose name shadows an alias filed before they existed — and picking
 * one of them would be right half the time and silent the rest.
 */
final class AliasResolution
{
    /**
     * @param string                $name        as the bracket spelled it
     * @param string                $normalised  what it was looked up by
     * @param list<AliasSuggestion> $suggestions best first; empty when nothing came close
     */
    private function __construct(
        public readonly string $name,
        public readonly string $normalised,
        public readonly ?Player $player,
        public readonly AliasMatch $match,
        public readonly array $suggestions,
    ) {
    }

    public static function blader(string $name, string $normalised, Player $player, AliasMatch $match): self
    {
        return new self($name, $normalised, $player, $match, []);
    }

    /**
     * @param list<AliasSuggestion> $suggestions
     */
    public static function question(string $name, string $normalised, array $suggestions): self
    {
        return new self($name, $normalised, null, AliasMatch::None, $suggestions);
    }

    /**
     * @param list<AliasSuggestion> $claimants everyone the spelling reaches
     */
    public static function ambiguous(string $name, string $normalised, array $claimants): self
    {
        return new self($name, $normalised, null, AliasMatch::Ambiguous, $claimants);
    }

    public function isResolved(): bool
    {
        return null !== $this->player;
    }

    public function isAmbiguous(): bool
    {
        return AliasMatch::Ambiguous === $this->match;
    }

    /**
     * The line an importer stops on.
     */
    public function problem(): string
    {
        if ($this->isResolved()) {
            return '';
        }

        if ($this->isAmbiguous()) {
            return sprintf(
                '"%s" is how more than one blader is already spelled: %s. Nothing can be read out of it until that is settled.',
                $this->name,
                $this->reasons(),
            );
        }

        if ([] === $this->suggestions) {
            return sprintf('"%s" is nobody the league knows, and nothing is close to it.', $this->name);
        }

        return sprintf(
            '"%s" is nobody the league knows. It could be %s.',
            $this->name,
            $this->reasons(),
        );
    }

    private function reasons(): string
    {
        return implode('; or ', array_map(
            static fn (AliasSuggestion $suggestion): string => sprintf(
                '%s (%s)',
                $suggestion->player->getName(),
                $suggestion->because(),
            ),
            $this->suggestions,
        ));
    }
}
