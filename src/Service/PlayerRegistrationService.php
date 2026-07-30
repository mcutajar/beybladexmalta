<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Entity\SeasonRegistration;
use App\Repository\PlayerRepositoryInterface;
use App\Repository\SeasonRegistrationRepository;
use App\Repository\SeasonRepository;
use Psr\Log\LoggerInterface;

class PlayerRegistrationService
{
    public function __construct(
        private PlayerRepositoryInterface $players,
        private SeasonRepository $seasons,
        private SeasonRegistrationRepository $registrations,
        private LedgerService $ledgerService,
        private LoggerInterface $logger,
        private FlusherInterface $flusher)
    {
    }

    public function register(
        string $playerName,
        string $seasonSlug,
    ): RegisterSeasonPaymentResult {
        $seasonSlug = trim($seasonSlug);
        $playerName = trim($playerName);
        $season = $this->seasons->findBySlug($seasonSlug);

        if (null === $season) {
            $this->logger->error('Season not found', [
                'slug' => $seasonSlug,
            ]);

            return RegisterSeasonPaymentResult::SeasonNotFound;
        }

        $player = $this->players->findByName($playerName);

        if (null === $player) {
            $player = new Player();
            $player->setName($playerName);
            $this->players->save($player);

            $this->logger->info('New player record generated', [
                'name' => $player->getName(),
            ]);
        }

        $registration = $this->registrations
            ->findForPlayerAndSeason($player, $season);

        if (null === $registration) {
            $registration = new SeasonRegistration();
            $registration->setPlayer($player)->setSeason($season);
            $this->registrations->save($registration);
        }

        if (!$registration->markAsPaid()) {
            return RegisterSeasonPaymentResult::AlreadyPaid;
        }

        $this->ledgerService->logRegistrationAttempt($season->getSlug(), $player->getName());

        $this->flusher->flush();

        return RegisterSeasonPaymentResult::Registered;
    }
}
