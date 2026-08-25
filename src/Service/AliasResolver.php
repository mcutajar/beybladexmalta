<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AliasIndex;
use App\Dto\AliasMatch;
use App\Dto\AliasResolution;
use App\Dto\AliasSuggestion;
use App\Dto\AliasSuggestionReason;
use App\Entity\Player;
use App\Repository\PlayerAliasRepository;
use App\Repository\PlayerRepositoryInterface;

/**
 * Turns a name off a bracket into the blader it belongs to, or into a
 * question.
 *
 * The one rule: **it never creates anybody.** A display name that reaches
 * nobody comes back unresolved with suggestions attached, and the caller stops.
 * That is what keeps a seventy-seventh blader out of the table, and it is why
 * the import in #53 can show a preview at all — a preview is only worth
 * looking at if the alternative to approving it is nothing happening.
 *
 * Resolution is two lookups against normalised spellings, in one namespace:
 * a blader's own name first, then the alias table. The two cannot collide,
 * because AliasService refuses to file an alias that folds onto somebody's
 * name, so the order is a statement of precedence rather than a tie-break.
 *
 * When both miss, the suggestion pass runs. Nothing it produces is ever acted
 * on here; it exists so that the person answering the question has the three
 * or four bladers worth considering in front of them instead of a list of
 * seventy-six.
 */
class AliasResolver
{
    /**
     * How many bladers to put in front of somebody. A longer list is not a
     * better answer to "which of these is it" — it is the question again.
     */
    private const int SUGGESTIONS = 5;

    /**
     * Edits between two normalised spellings that still counts as close.
     *
     * Two, which is what separates `obelisk` from `obelix` — and they are two
     * people. The number is set where it is *because* of that pair rather than
     * in spite of it: a threshold that excluded the dangerous suggestion would
     * exclude the useful ones with it, and a suggestion is not a decision.
     */
    private const int CLOSE_ENOUGH = 2;

    /**
     * Below this, an edit distance says nothing. `jg` is two edits from `jean`
     * and from `jack`, and offering both is offering noise.
     */
    private const int TOO_SHORT_TO_COMPARE = 4;

    public function __construct(
        private PlayerRepositoryInterface $players,
        private PlayerAliasRepository $aliases,
        private AliasNormaliser $normaliser,
    ) {
    }

    /**
     * @param ?string $challongeAccount the account a bracket rendered in place
     *                                  of the name, when there was one
     */
    public function resolve(string $name, ?string $challongeAccount = null): AliasResolution
    {
        return $this->against($this->index(), $name, $challongeAccount);
    }

    /**
     * The same question asked of a whole bracket, reading the two tables once.
     *
     * @param list<string> $names
     *
     * @return list<AliasResolution>
     */
    public function resolveAll(array $names): array
    {
        $index = $this->index();

        return array_map(
            fn (string $name): AliasResolution => $this->against($index, $name),
            $names,
        );
    }

    /**
     * Every spelling the league knows, read out of the two tables.
     */
    public function index(): AliasIndex
    {
        $bladers = [];

        foreach ($this->players->findAll() as $player) {
            $bladers[$this->normaliser->normalise($player->getName())][] = $player;
        }

        $aliases = [];

        foreach ($this->aliases->all() as $alias) {
            $aliases[$alias->getNormalised()] = $alias->getPlayer();
        }

        return new AliasIndex($bladers, $aliases);
    }

    private function against(AliasIndex $index, string $name, ?string $challongeAccount = null): AliasResolution
    {
        $normalised = $this->normaliser->normalise($name);

        if ('' === $normalised) {
            return AliasResolution::question($name, $normalised, []);
        }

        $bladers = $index->bladersCalled($normalised);

        if (1 === count($bladers)) {
            return AliasResolution::blader($name, $normalised, $bladers[0], AliasMatch::BladerName);
        }

        /*
         * Two bladers whose names fold together settle nothing. The table is
         * unique on the raw name, so `Rip N' Burst` and `Ripnburst` can both
         * exist; picking one of them here would be right half the time and
         * silent the rest. They go out as the suggestions instead, which is
         * how the merge in #56 gets asked for.
         */
        if (count($bladers) > 1) {
            return AliasResolution::question($name, $normalised, array_map(
                static fn (Player $player): AliasSuggestion => new AliasSuggestion(
                    $player,
                    $normalised,
                    AliasSuggestionReason::Spelling,
                    0,
                ),
                $bladers,
            ));
        }

        $aliased = $index->aliasedTo($normalised);

        if (null !== $aliased) {
            return AliasResolution::blader($name, $normalised, $aliased, AliasMatch::Alias);
        }

        return AliasResolution::question(
            $name,
            $normalised,
            $this->suggestions($index, $normalised, $challongeAccount),
        );
    }

    /**
     * @return list<AliasSuggestion>
     */
    private function suggestions(AliasIndex $index, string $normalised, ?string $challongeAccount): array
    {
        $suggestions = $this->fromChallongeAccount($index, $challongeAccount);

        foreach ($index->spellings() as $known) {
            $suggestion = $this->compare($normalised, $known['spelling'], $known['player']);

            if (null !== $suggestion) {
                $suggestions[] = $suggestion;
            }
        }

        return $this->best($suggestions);
    }

    /**
     * An exact hit on the Challonge account a bracket rendered in place of the
     * name.
     *
     * A blader who linked their account is shown as that account in the
     * standings table while every match in the same bracket says their real
     * name, so the account is often the only string that reaches anybody. It
     * is still a suggestion rather than a resolution: an account is a login,
     * and two bladers in one house share one.
     *
     * @return list<AliasSuggestion>
     */
    private function fromChallongeAccount(AliasIndex $index, ?string $challongeAccount): array
    {
        if (null === $challongeAccount) {
            return [];
        }

        $account = $this->normaliser->normalise($challongeAccount);

        if ('' === $account) {
            return [];
        }

        $named = $index->bladersCalled($account);
        $player = $index->aliasedTo($account) ?? (1 === count($named) ? $named[0] : null);

        if (null === $player) {
            return [];
        }

        return [new AliasSuggestion($player, $account, AliasSuggestionReason::ChallongeAccount, 0)];
    }

    private function compare(string $normalised, string $known, Player $player): ?AliasSuggestion
    {
        /*
         * levenshtein() counts bytes rather than characters, so an accented
         * name is measured a little harshly. That costs a suggestion nobody
         * was going to be shown anyway, and the alternative is a hand-rolled
         * multibyte edit distance for a hint.
         */
        $distance = levenshtein($normalised, $known);

        if (0 === $distance) {
            return null;
        }

        if ($distance <= self::CLOSE_ENOUGH && mb_strlen($known) >= self::TOO_SHORT_TO_COMPARE) {
            return new AliasSuggestion($player, $known, AliasSuggestionReason::Spelling, $distance);
        }

        /*
         * The half of the problem edit distance is bad at, and it is most of
         * it: `bladerz` inside `bladerzmlt`, `belti` inside `ilbelti`,
         * `rizzler` inside `therizzler`, `guzman` inside `guzman93`. Three
         * edits apart, one stem, and obvious to anybody who was there.
         */
        if ($this->sharesAStem($normalised, $known)) {
            return new AliasSuggestion($player, $known, AliasSuggestionReason::PartOfAKnownName, $distance);
        }

        return null;
    }

    /**
     * Whether one spelling contains the other, with enough of it in common to
     * mean something. Four characters — below that, `jg` is inside `jgood` and
     * inside half the table.
     */
    private function sharesAStem(string $normalised, string $known): bool
    {
        $shorter = mb_strlen($normalised) <= mb_strlen($known) ? $normalised : $known;

        if (mb_strlen($shorter) < self::TOO_SHORT_TO_COMPARE) {
            return false;
        }

        return str_contains($normalised, $known) || str_contains($known, $normalised);
    }

    /**
     * Best first, one entry per blader, and no more than a person can weigh.
     *
     * @param list<AliasSuggestion> $suggestions
     *
     * @return list<AliasSuggestion>
     */
    private function best(array $suggestions): array
    {
        usort(
            $suggestions,
            static fn (AliasSuggestion $a, AliasSuggestion $b): int => [$a->reason->ordinal(), $a->distance, $a->spelling]
                <=> [$b->reason->ordinal(), $b->distance, $b->spelling],
        );

        $seen = [];
        $best = [];

        foreach ($suggestions as $suggestion) {
            $name = $suggestion->player->getName();

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $best[] = $suggestion;

            if (self::SUGGESTIONS === count($best)) {
                break;
            }
        }

        return $best;
    }
}
