<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PlayerAliasRejection;
use App\Repository\PlayerAliasRejectionRepository;

class AliasRejectionService
{
    public function __construct(
        private PlayerAliasRejectionRepository $rejections,
        private AliasResolver $resolver,
        private AliasNormaliser $normaliser,
        private LedgerService $ledger,
        private FlusherInterface $flusher,
    ) {
    }

    public function reject(string $bladerName, string $spelling): RejectAliasSuggestionResult
    {
        $spelling = trim($spelling);
        $normalised = $this->normaliser->normalise($spelling);

        if ('' === $normalised) {
            return RejectAliasSuggestionResult::NotAName;
        }

        $resolution = $this->resolver->resolve($bladerName);
        $player = $resolution->player;

        if (null === $player) {
            return $resolution->isAmbiguous()
                ? RejectAliasSuggestionResult::BladerIsAmbiguous
                : RejectAliasSuggestionResult::BladerNotFound;
        }

        if (null !== $this->rejections->findPair($player, $normalised)) {
            return RejectAliasSuggestionResult::AlreadyRejected;
        }

        $this->rejections->save(new PlayerAliasRejection($player, $spelling, $normalised));
        $this->flusher->flushThen(fn () => $this->ledger->logAliasSuggestionRejected($player->getName(), $spelling));

        return RejectAliasSuggestionResult::Rejected;
    }

    public function allow(string $bladerName, string $spelling): RejectAliasSuggestionResult
    {
        $normalised = $this->normaliser->normalise(trim($spelling));

        if ('' === $normalised) {
            return RejectAliasSuggestionResult::NotAName;
        }

        $resolution = $this->resolver->resolve($bladerName);
        $player = $resolution->player;

        if (null === $player) {
            return $resolution->isAmbiguous()
                ? RejectAliasSuggestionResult::BladerIsAmbiguous
                : RejectAliasSuggestionResult::BladerNotFound;
        }

        $rejection = $this->rejections->findPair($player, $normalised);

        if (null === $rejection) {
            return RejectAliasSuggestionResult::NotRejected;
        }

        $this->rejections->remove($rejection);
        $this->flusher->flushThen(fn () => $this->ledger->logAliasSuggestionAllowed($player->getName(), $spelling));

        return RejectAliasSuggestionResult::Allowed;
    }
}
