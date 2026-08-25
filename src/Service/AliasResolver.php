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
 * Resolution is one lookup against one namespace. A blader's own name and an
 * alias are two ways of spelling the same claim, and this class does not rank
 * them: it collects everybody the normalised spelling reaches, and answers
 * only when that is exactly one person.
 *
 * Reaching two is not a tie to break. `AliasService` refuses to file an alias
 * onto a blader's name, but that rule only guards the alias side, and the
 * league gains bladers the other way — `app:import-tournament` auto-creates
 * them from a placement list. So a blader created after the fact can shadow an
 * alias filed before they existed, and preferring either one would split
 * somebody's career across two rows without saying so. Both come back as the
 * answer instead, which is what makes the collision visible the first time
 * anything asks. Closing the hole at the point of creation belongs to #54,
 * where the console commands stop inventing bladers at all.
 *
 * When nobody is reached, the suggestion pass runs. Nothing it produces is
 * ever acted on here; it exists so that the person answering the question has
 * the three or four bladers worth considering in front of them instead of a
 * list of seventy-six.
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
        return $this->resolveWith($this->index(), $name, $challongeAccount);
    }

    /**
     * The same question asked of a whole bracket, reading the two tables once.
     *
     * Each entry carries its own account, because that is often the only
     * string in a standings row that reaches anybody — a blader who linked
     * their Challonge account is rendered as the account there and under their
     * own name in every match. A bulk call that could not carry it would be
     * the shape #53 needs and the one that loses the suggestion it needs most.
     *
     * @param list<array{name: string, account?: ?string}> $names
     *
     * @return list<AliasResolution>
     */
    public function resolveAll(array $names): array
    {
        $index = $this->index();

        return array_map(
            fn (array $entry): AliasResolution => $this->resolveWith(
                $index,
                $entry['name'],
                $entry['account'] ?? null,
            ),
            $names,
        );
    }

    /**
     * One name against an index somebody else built.
     *
     * Public so that a caller resolving several names in one operation reads
     * the two tables once rather than once per name — which `AliasService`
     * does, and which matters when #51 seeds sixty aliases in a loop. An index
     * goes stale the moment an alias is written, so it is passed in rather
     * than cached here.
     */
    public function resolveWith(AliasIndex $index, string $name, ?string $challongeAccount = null): AliasResolution
    {
        $normalised = $this->normaliser->normalise($name);

        if ('' === $normalised) {
            return AliasResolution::question($name, $normalised, []);
        }

        $claimants = $this->everybodyCalled($index, $normalised);

        if (count($claimants) > 1) {
            return AliasResolution::ambiguous($name, $normalised, $this->collision($index, $claimants, $normalised, $challongeAccount));
        }

        $bladers = $index->bladersCalled($normalised);

        if (1 === count($bladers)) {
            return AliasResolution::blader($name, $normalised, $bladers[0], AliasMatch::BladerName);
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

    /**
     * Everybody this exact spelling reaches, by their own name or by an alias.
     *
     * @return list<Player>
     */
    private function everybodyCalled(AliasIndex $index, string $normalised): array
    {
        $claimants = $index->bladersCalled($normalised);
        $aliased = $index->aliasedTo($normalised);

        if (null !== $aliased && !in_array($aliased, $claimants, true)) {
            $claimants[] = $aliased;
        }

        return $claimants;
    }

    /**
     * The shortlist for a spelling more than one blader answers to.
     *
     * Only the claimants, plus the account if it points at one of them — a
     * name that is already exactly two people's is not made clearer by
     * offering a third who is nearly spelled like it. The account is worth
     * consulting here of all places: where the collision is what stops the
     * row being read, an exact hit on a linked account is the one fact that
     * says which side of it was meant.
     *
     * @param list<Player> $claimants
     *
     * @return list<AliasSuggestion>
     */
    private function collision(AliasIndex $index, array $claimants, string $normalised, ?string $challongeAccount): array
    {
        return $this->best(array_merge(
            $this->fromChallongeAccount($index, $challongeAccount),
            array_map(
                static fn (Player $player): AliasSuggestion => new AliasSuggestion(
                    $player,
                    $normalised,
                    AliasSuggestionReason::SpelledTheSameWay,
                    0,
                ),
                $claimants,
            ),
        ));
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

        $claimants = $this->everybodyCalled($index, $account);

        if (1 !== count($claimants)) {
            return [];
        }

        return [new AliasSuggestion($claimants[0], $account, AliasSuggestionReason::ChallongeAccount, 0)];
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
     * The blader's name is the last tie-break rather than the first thing left
     * to chance. Two suggestions that agree on reason, distance and spelling
     * — which is every collision — would otherwise come out in whatever order
     * `findAll()` happened to return, and an unordered shortlist is a
     * different shortlist on every run.
     *
     * @param list<AliasSuggestion> $suggestions
     *
     * @return list<AliasSuggestion>
     */
    private function best(array $suggestions): array
    {
        usort(
            $suggestions,
            static fn (AliasSuggestion $a, AliasSuggestion $b): int => [$a->reason->ordinal(), $a->distance, $a->spelling, $a->player->getName()]
                <=> [$b->reason->ordinal(), $b->distance, $b->spelling, $b->player->getName()],
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
