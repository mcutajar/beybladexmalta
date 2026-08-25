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

    public function isResolved(): bool
    {
        return null !== $this->player;
    }

    /**
     * The line an importer stops on.
     */
    public function problem(): string
    {
        if ($this->isResolved()) {
            return '';
        }

        if ([] === $this->suggestions) {
            return sprintf('"%s" is nobody the league knows, and nothing is close to it.', $this->name);
        }

        return sprintf(
            '"%s" is nobody the league knows. It could be %s.',
            $this->name,
            implode('; or ', array_map(
                static fn (AliasSuggestion $suggestion): string => sprintf(
                    '%s (%s)',
                    $suggestion->player->getName(),
                    $suggestion->because(),
                ),
                $this->suggestions,
            )),
        );
    }
}
