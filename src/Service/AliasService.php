<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AliasIndex;
use App\Dto\AliasResolution;
use App\Entity\Player;
use App\Entity\PlayerAlias;
use App\Entity\PlayerAliasSource;
use App\Repository\PlayerAliasRepository;
use Psr\Log\LoggerInterface;

/**
 * The writing half of the alias table.
 *
 * AliasResolver answers questions about spellings; this is where the answers
 * are put on file, and it is the only thing that writes there. Two rules it
 * enforces that nothing downstream then has to think about:
 *
 * 1. **An alias never creates a blader.** Filing `Anzjan` against somebody the
 *    league has never heard of would create the seventy-seventh blader by the
 *    back door, which is the exact failure the alias table exists to prevent.
 *    The blader has to be there first.
 * 2. **Aliases and blader names are one namespace.** A spelling that folds
 *    onto somebody's actual name is refused rather than filed, so a resolver
 *    never has to decide which of the two wins. Where that refusal is wrong —
 *    two rows for one person — the answer is the merge in #56, not an alias
 *    quietly pointing one name at the other.
 *
 * The second rule only guards this side. Bladers also arrive by being invented
 * from a placement list, and `app:import-tournament` still does that, so a
 * blader created later can shadow an alias filed before they existed. Nothing
 * here can prevent it; `AliasResolver` refuses to resolve the collision rather
 * than pick a side, and #54 closes it at the point of creation.
 */
class AliasService
{
    public function __construct(
        private PlayerAliasRepository $aliases,
        private AliasResolver $resolver,
        private AliasNormaliser $normaliser,
        private LedgerService $ledgerService,
        private FlusherInterface $flusher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Records that `$alias` is how `$bladerName` was spelled somewhere.
     *
     * The blader is looked up through the resolver rather than by name alone,
     * so an alias can be filed against a blader named the way an earlier alias
     * already says they are spelled. It resolves or it refuses; it does not
     * approximate.
     */
    public function add(
        string $bladerName,
        string $alias,
        PlayerAliasSource $source = PlayerAliasSource::Manual,
    ): AddAliasResult {
        $alias = trim($alias);
        $normalised = $this->normaliser->normalise($alias);

        if ('' === $normalised) {
            return AddAliasResult::NotAName;
        }

        /*
         * One index for the whole operation, which is what AliasIndex is for.
         * The blader lookup and the namespace check both read the same two
         * tables, and `app:bootstrap-aliases` comes through here once per
         * alias it seeds.
         */
        $index = $this->resolver->index();
        $blader = $this->resolver->resolveWith($index, $bladerName);
        $player = $blader->player;

        if (null === $player) {
            $this->logger->warning('Alias rejected: the blader named is not one blader', [
                'blader' => $bladerName,
                'alias' => $alias,
                'why' => $blader->match->value,
            ]);

            return $blader->isAmbiguous()
                ? AddAliasResult::BladerIsAmbiguous
                : AddAliasResult::BladerNotFound;
        }

        $clash = $this->clashWithABladersName($index, $normalised, $player);

        if (null !== $clash) {
            return $clash;
        }

        $existing = $this->aliases->findByNormalised($normalised);

        if (null !== $existing) {
            return $existing->getPlayer() === $player
                ? AddAliasResult::AlreadyRecorded
                : AddAliasResult::TakenByAnotherBlader;
        }

        $this->aliases->save(new PlayerAlias($player, $alias, $normalised, $source));

        /*
         * The replay command is written inside the same transaction as the
         * flush. A rebuilt database is repeat.sh replayed from nothing, so an
         * alias missing from the ledger is an alias that stops existing the
         * next time the schema is recreated.
         */
        $this->flusher->flushThen(
            fn () => $this->ledgerService->logAliasAdded(
                bladerName: $player->getName(),
                alias: $alias,
                source: $source,
            ),
        );

        return AddAliasResult::Added;
    }

    public function remove(string $alias): RemoveAliasResult
    {
        $alias = trim($alias);
        $normalised = $this->normaliser->normalise($alias);

        if ('' === $normalised) {
            return RemoveAliasResult::NotAName;
        }

        $existing = $this->aliases->findByNormalised($normalised);

        if (null === $existing) {
            return RemoveAliasResult::NotFound;
        }

        $this->aliases->remove($existing);

        $this->flusher->flushThen(
            fn () => $this->ledgerService->logAliasRemoved($alias),
        );

        return RemoveAliasResult::Removed;
    }

    /**
     * Every alias on file, or one blader's.
     *
     * @return list<PlayerAlias>
     */
    public function all(?Player $player = null): array
    {
        return null === $player ? $this->aliases->all() : $this->aliases->forPlayer($player);
    }

    /**
     * Who an unresolved spelling might be — the resolver's question, handed
     * straight back so a command can print the shortlist it came with.
     */
    public function whoCouldThisBe(string $name): AliasResolution
    {
        return $this->resolver->resolve($name);
    }

    /**
     * Whether the spelling is already somebody's name rather than a nickname
     * for one.
     */
    private function clashWithABladersName(AliasIndex $index, string $normalised, Player $player): ?AddAliasResult
    {
        $bladers = $index->bladersCalled($normalised);

        if ([] === $bladers) {
            return null;
        }

        foreach ($bladers as $blader) {
            if ($blader === $player) {
                return AddAliasResult::IsTheirOwnName;
            }
        }

        return AddAliasResult::IsAnotherBladersName;
    }
}
