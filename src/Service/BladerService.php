<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Repository\PlayerRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Putting a blader on record deliberately.
 *
 * Bladers used to arrive only as a side effect: `app:import-tournament` and
 * `app:register-payment` invent one for any name they have never seen, because
 * both read something a person typed alongside the evening it describes. The
 * import screen cannot work that way — a bracket is forty display names and
 * two hundred and seven spellings across the corpus belong to seventy-six
 * people — so there it is a decision, made once, by somebody who was there.
 *
 * Which means it needs a line of its own in the ledger, and this is why:
 * **most of the bladers created this way will never appear on a placement
 * list.** Fifty-two of the unresolved spellings across the captured brackets
 * finished eleventh or worse, so they are archived rather than scored, and
 * `var/data/imports/*.txt` stops at ten. Without `app:create-blader` they
 * would exist until the next schema rebuild and then stop existing, taking
 * every match attached to them with them.
 *
 * It never resolves an alias. Deciding that a spelling belongs to somebody
 * already on record is `AliasService`'s job, and it is the other half of the
 * same answer.
 */
class BladerService
{
    public function __construct(
        private PlayerRepositoryInterface $players,
        private LedgerService $ledgerService,
        private FlusherInterface $flusher,
        private LoggerInterface $logger,
    ) {
    }

    public function create(string $name): CreateBladerResult
    {
        $name = trim($name);

        if ('' === $name) {
            return CreateBladerResult::NotAName;
        }

        if (null !== $this->players->findByName($name)) {
            return CreateBladerResult::AlreadyOnRecord;
        }

        $blader = new Player();
        $blader->setName($name);

        $this->players->save($blader);

        /*
         * Inside the same transaction as the flush, like every other admin
         * action. A blader missing from the ledger is a blader who stops
         * existing at the next schema rebuild.
         */
        $this->flusher->flushThen(
            fn () => $this->ledgerService->logBladerCreated($name),
        );

        $this->logger->info('Blader put on record', ['name' => $name]);

        return CreateBladerResult::Created;
    }
}
