<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\MergePlayerPlan;
use App\Entity\Player;
use App\Entity\PlayerAlias;
use App\Entity\PlayerAliasSource;
use App\Entity\PlayerMergeRedirect;
use App\Repository\PlayerAliasRejectionRepository;
use App\Repository\PlayerAliasRepository;
use App\Repository\PlayerMergeRedirectRepository;
use App\Repository\PlayerMergeRepository;
use App\Repository\PlayerRepository;

final class PlayerMergeService
{
    public function __construct(
        private readonly PlayerRepository $players,
        private readonly PlayerMergeRepository $history,
        private readonly PlayerAliasRepository $aliases,
        private readonly PlayerAliasRejectionRepository $rejections,
        private readonly PlayerMergeRedirectRepository $redirects,
        private readonly AliasNormaliser $normaliser,
        private readonly FlusherInterface $flusher,
        private readonly LedgerService $ledger,
    ) {
    }

    public function planNames(string $fromName, string $intoName): MergePlayerPlan
    {
        $from = $this->players->findByName($fromName);
        $into = $this->players->findByName($intoName);

        if (null === $from && null !== $into) {
            $alias = $this->aliases->findByNormalised($this->normaliser->normalise($fromName));
            if ($alias?->getPlayer() === $into) {
                return new MergePlayerPlan(MergePlayerResult::AlreadyMerged, into: $into);
            }
        }

        if (null === $from || null === $into) {
            return new MergePlayerPlan(MergePlayerResult::PlayerNotFound, detail: 'Both bladers must exist under their league names.');
        }

        return $this->plan($from, $into);
    }

    public function plan(Player $from, Player $into): MergePlayerPlan
    {
        if ($from === $into) {
            return new MergePlayerPlan(MergePlayerResult::SamePlayer, $from, $into, detail: 'The losing and surviving blader are the same player.');
        }

        $results = $this->history->results($from);
        $intoResults = $this->history->results($into);
        foreach ($results as $result) {
            foreach ($intoResults as $intoResult) {
                if ($result->getTournament() === $intoResult->getTournament()) {
                    return new MergePlayerPlan(MergePlayerResult::Conflict, $from, $into, detail: sprintf('Both bladers have a result in "%s".', $result->getTournament()->getTitle()));
                }
            }
        }

        $registrations = $this->history->registrations($from);
        $intoRegistrations = $this->history->registrations($into);
        foreach ($registrations as $registration) {
            foreach ($intoRegistrations as $intoRegistration) {
                if ($registration->getSeason() === $intoRegistration->getSeason()) {
                    return new MergePlayerPlan(MergePlayerResult::Conflict, $from, $into, detail: sprintf('Both bladers are registered for "%s".', $registration->getSeason()->getName()));
                }
            }
        }

        $memberships = $this->history->teamMemberships($from);
        $intoMemberships = $this->history->teamMemberships($into);
        foreach ($memberships as $membership) {
            foreach ($intoMemberships as $intoMembership) {
                if ($membership->getTeam() === $intoMembership->getTeam()) {
                    return new MergePlayerPlan(MergePlayerResult::Conflict, $from, $into, detail: sprintf('Both bladers belong to team "%s" in "%s".', $membership->getTeam()->getName(), $membership->getTeam()->getTournament()->getTitle()));
                }
            }
        }

        $aliases = $this->aliases->forPlayer($from);
        $losingNormalised = $this->normaliser->normalise($from->getName());
        $existingLosingName = $this->aliases->findByNormalised($losingNormalised);
        if (null !== $existingLosingName && $existingLosingName->getPlayer() !== $from && $existingLosingName->getPlayer() !== $into) {
            return new MergePlayerPlan(MergePlayerResult::Conflict, $from, $into, detail: sprintf('The losing name "%s" is already claimed as an alias of "%s".', $from->getName(), $existingLosingName->getPlayer()->getName()));
        }

        foreach ($this->players->allExcept($from) as $player) {
            if ($player !== $into && $this->normaliser->normalise($player->getName()) === $losingNormalised) {
                return new MergePlayerPlan(MergePlayerResult::Conflict, $from, $into, detail: sprintf('The losing name "%s" normalises to the league name of "%s".', $from->getName(), $player->getName()));
            }
            foreach ($aliases as $alias) {
                if ($player !== $into && $this->normaliser->normalise($player->getName()) === $alias->getNormalised()) {
                    return new MergePlayerPlan(MergePlayerResult::Conflict, $from, $into, detail: sprintf('Alias "%s" conflicts with the league name "%s".', $alias->getAlias(), $player->getName()));
                }
            }
        }

        $rejections = $this->rejections->findBy(['player' => $from]);
        $reconciled = [];
        $claims = [$losingNormalised, $this->normaliser->normalise($into->getName())];
        foreach (array_merge($aliases, $this->aliases->forPlayer($into)) as $alias) {
            $claims[] = $alias->getNormalised();
        }
        foreach ($rejections as $rejection) {
            if (null !== $this->rejections->findPair($into, $rejection->getNormalised()) || in_array($rejection->getNormalised(), $claims, true)) {
                $reconciled[] = $rejection;
            }
        }
        foreach ($this->rejections->findBy(['player' => $into]) as $rejection) {
            if (in_array($rejection->getNormalised(), $claims, true)) {
                $reconciled[] = $rejection;
            }
        }

        return new MergePlayerPlan(
            MergePlayerResult::Ready,
            $from,
            $into,
            $results,
            $this->history->participants($from),
            $registrations,
            $memberships,
            $aliases,
            $rejections,
            $reconciled,
            $this->redirects->pointingTo($from),
            null === $existingLosingName,
        );
    }

    public function merge(MergePlayerPlan $plan): MergePlayerResult
    {
        if (!$plan->isReady() || null === $plan->from || null === $plan->into || null === $plan->from->getId()) {
            return $plan->result;
        }

        foreach ($plan->results as $result) {
            $result->setPlayer($plan->into);
        }
        foreach ($plan->participants as $participant) {
            $participant->isBlader($plan->into);
        }
        foreach ($plan->registrations as $registration) {
            $registration->setPlayer($plan->into);
        }
        foreach ($plan->teamMemberships as $membership) {
            $membership->belongsTo($plan->into);
        }
        foreach ($plan->aliases as $alias) {
            $alias->belongsTo($plan->into);
        }
        foreach ($plan->rejections as $rejection) {
            if (in_array($rejection, $plan->reconciledRejections, true)) {
                $this->rejections->remove($rejection);
            } else {
                $rejection->belongsTo($plan->into);
            }
        }
        foreach ($plan->reconciledRejections as $rejection) {
            if ($rejection->getPlayer() === $plan->into) {
                $this->rejections->remove($rejection);
            }
        }
        if ($plan->addLosingNameAlias) {
            $this->aliases->save(new PlayerAlias($plan->into, $plan->from->getName(), $this->normaliser->normalise($plan->from->getName()), PlayerAliasSource::Manual));
        }

        foreach ($plan->existingRedirects as $redirect) {
            $redirect->pointsTo($plan->into);
        }
        /*
         * Both of the losing blader's public URLs, kept: the id form every
         * season-scoped route used, and the slug form #95 made canonical. The
         * row is deleted a line below, so this is the only thing that will
         * remember either of them.
         */
        $this->redirects->save(new PlayerMergeRedirect($plan->from->getId(), $plan->from->getSlug(), $plan->into));
        $this->players->remove($plan->from);
        $this->flusher->flushThen(fn () => $this->ledger->logPlayerMerged($plan->from->getName(), $plan->into->getName()));

        return MergePlayerResult::Merged;
    }
}
