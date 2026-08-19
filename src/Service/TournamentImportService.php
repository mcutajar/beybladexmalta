<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TournamentPlacement;
use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentResult;
use App\Repository\PlayerRepositoryInterface;
use App\Repository\SeasonRepository;
use App\Repository\TournamentRepository;
use App\Repository\TournamentResultRepository;
use Psr\Log\LoggerInterface;

class TournamentImportService
{
    private const array F1_MATRIX = [
        1 => 25, 2 => 20, 3 => 15, 4 => 12, 5 => 10,
        6 => 8,  7 => 6,  8 => 4,  9 => 2,  10 => 1,
    ];

    private const int KNOCKOUT_WINNER_BONUS = 10;

    public function __construct(
        private PlayerRepositoryInterface $players,
        private SeasonRepository $seasons,
        private TournamentRepository $tournaments,
        private TournamentResultRepository $results,
        private ImportFileWriter $importFileWriter,
        private LedgerService $ledgerService,
        private LoggerInterface $logger,
        private FlusherInterface $flusher,
    ) {
    }

    /**
     * Imports an ordered placement list, awarding F1 points by finishing rank.
     *
     * @param list<TournamentPlacement> $placements     in finishing order, best first
     * @param ?string                   $sourceFilePath the replayable placement file, generated when absent
     */
    public function import(
        string $title,
        string $heldOn,
        string $seasonSlug,
        array $placements,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
        ?string $sourceFilePath = null,
    ): TournamentImportResult {
        $title = trim($title);
        $seasonSlug = trim($seasonSlug);
        $date = $this->parseDate(trim($heldOn));

        if (null === $date) {
            $this->logger->warning('Tournament import rejected: malformed date', [
                'heldOn' => $heldOn,
            ]);

            return TournamentImportResult::InvalidDate;
        }

        if ([] === $placements) {
            $this->logger->warning('Tournament import rejected: empty placement list', [
                'title' => $title,
            ]);

            return TournamentImportResult::NoPlacements;
        }

        $season = $this->seasons->findBySlug($seasonSlug);

        if (null === $season) {
            $this->logger->error('Season not found', [
                'slug' => $seasonSlug,
            ]);

            return TournamentImportResult::SeasonNotFound;
        }

        $tournament = new Tournament();
        $tournament->setTitle($title);
        $tournament->setHeldOn($date);
        $tournament->setChallongeUrl($challongeUrl);
        $tournament->setSeason($season);

        $this->tournaments->save($tournament);

        $rank = 1;

        foreach ($placements as $placement) {
            $this->results->save(
                $this->buildResult(
                    tournament: $tournament,
                    placement: $placement,
                    rank: $rank,
                    knockoutWinner: $knockoutWinner,
                ),
            );

            ++$rank;
        }

        /*
         * The ledger and the placement file are written before the flush so
         * that a recovery artifact failure cancels the import outright,
         * rather than leaving a tournament that cannot be replayed.
         */
        $sourceFilePath ??= $this->importFileWriter->write(
            $title,
            $date,
            $placements,
        );

        $this->ledgerService->logTournamentImport(
            title: $title,
            heldOn: $date->format('Y-m-d'),
            sourceFilePath: $sourceFilePath,
            seasonSlug: $seasonSlug,
            challongeUrl: $challongeUrl,
            knockoutWinner: $knockoutWinner,
        );

        $this->flusher->flush();

        return TournamentImportResult::Imported;
    }

    private function buildResult(
        Tournament $tournament,
        TournamentPlacement $placement,
        int $rank,
        ?string $knockoutWinner,
    ): TournamentResult {
        $player = $this->players->findByName($placement->playerName);

        if (null === $player) {
            $player = new Player();
            $player->setName(trim($placement->playerName));
            $this->players->save($player);

            $this->logger->info('New player record generated', [
                'name' => $player->getName(),
            ]);
        }

        $bonusPoints = $placement->bonusPoints;

        if ($this->hasWonKnockout($placement->playerName, $knockoutWinner)) {
            $bonusPoints += self::KNOCKOUT_WINNER_BONUS;
        }

        $result = new TournamentResult();
        $result->setTournament($tournament);
        $result->setPlayer($player);
        $result->setRank($rank);
        $result->setF1Points(self::F1_MATRIX[$rank] ?? 0);
        $result->setBonusPoints($bonusPoints);

        return $result;
    }

    private function hasWonKnockout(
        string $playerName,
        ?string $knockoutWinner,
    ): bool {
        if (null === $knockoutWinner || '' === trim($knockoutWinner)) {
            return false;
        }

        return 0 === strcasecmp($playerName, trim($knockoutWinner));
    }

    /**
     * Accepts the strict YYYY-MM-DD format only, so that a mistyped date is
     * rejected rather than silently reinterpreted.
     */
    private function parseDate(string $heldOn): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $heldOn);

        if (false === $date || $date->format('Y-m-d') !== $heldOn) {
            return null;
        }

        return $date;
    }
}
