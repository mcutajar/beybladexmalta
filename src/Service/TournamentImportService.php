<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\TeamPlacement;
use App\Dto\TournamentPlacement;
use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentResult;
use App\Entity\TournamentTeam;
use App\Repository\PlayerRepositoryInterface;
use App\Repository\SeasonRepository;
use App\Repository\TournamentRepository;
use App\Repository\TournamentResultRepository;
use App\Repository\TournamentTeamRepository;
use Psr\Log\LoggerInterface;

class TournamentImportService
{
    private const int KNOCKOUT_WINNER_BONUS = 10;

    /**
     * Challonge's own filler entrant. It is a slot in a bracket rather than
     * somebody who turned up, so it becomes no team and no result — and the
     * entrants below it keep the rank the bracket gave them, because the ranks
     * are Challonge's and renumbering around a dropped row would invent an
     * order nobody played.
     *
     * The line it draws is the point of the whole ticket: `bye` is dropped
     * because it is not an entrant, and an unclaimed team is kept because it
     * is one.
     */
    private const string BYE = 'bye';

    public function __construct(
        private PlayerRepositoryInterface $players,
        private SeasonRepository $seasons,
        private TournamentRepository $tournaments,
        private TournamentResultRepository $results,
        private TournamentTeamRepository $teams,
        private ImportFileWriter $importFileWriter,
        private LedgerService $ledgerService,
        private LoggerInterface $logger,
        private FlusherInterface $flusher,
        private F1Points $f1Points,
        private AliasNormaliser $normaliser,
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

        $opened = $this->open($title, $heldOn, $seasonSlug, $challongeUrl, [] === $placements);

        if ($opened instanceof TournamentImportResult) {
            return $opened;
        }

        $rank = 1;

        foreach ($placements as $placement) {
            $this->results->save(
                $this->buildResult(
                    tournament: $opened,
                    placement: $placement,
                    rank: $rank,
                    knockoutWinner: $knockoutWinner,
                ),
            );

            ++$rank;
        }

        /*
         * The recovery artifacts are written inside the same transaction as
         * the flush. A failed artifact write cancels the import, and a failed
         * import can never leave a replay command behind for a tournament
         * that was not stored.
         */
        $this->flusher->flushThen(
            fn () => $this->writeRecoveryArtifacts(
                title: $title,
                heldOn: $opened->getHeldOn(),
                seasonSlug: $seasonSlug,
                placements: $placements,
                challongeUrl: $challongeUrl,
                knockoutWinner: $knockoutWinner,
                sourceFilePath: $sourceFilePath,
            ),
        );

        return TournamentImportResult::Imported;
    }

    /**
     * Imports a 2v2 event as one tournament, expanding each entrant into one
     * placement per blader in it.
     *
     * A team bracket carries a finishing order and nothing else the league can
     * use: the entrants are team names, and a team match records only the
     * aggregate of its individual matchups, so there is no blader-level result
     * to be had. Points are awarded on the finishing order, which is exactly
     * what survives — so the team's rank becomes each member's rank, scored by
     * the same matrix, and no match, game or knockout bonus is written at all.
     *
     * An unclaimed team is stored with no members and produces no result. It
     * keeps its rank and can be claimed later, which is the one place in this
     * epic where a name nobody recognises becomes a record instead of a
     * question.
     *
     * @param list<TeamPlacement> $teams          in finishing order, best first
     * @param ?string             $sourceFilePath the replayable roster file, generated when absent
     */
    public function importTeamEvent(
        string $title,
        string $heldOn,
        string $seasonSlug,
        array $teams,
        ?string $challongeUrl = null,
        ?string $sourceFilePath = null,
    ): TournamentImportResult {
        $title = trim($title);
        $seasonSlug = trim($seasonSlug);

        $entrants = $this->entrants($teams);

        $opened = $this->open($title, $heldOn, $seasonSlug, $challongeUrl, [] === $entrants);

        if ($opened instanceof TournamentImportResult) {
            return $opened;
        }

        foreach ($entrants as $entrant) {
            $team = new TournamentTeam(
                tournament: $opened,
                name: $entrant->teamName,
                normalised: $this->normaliser->normalise($entrant->teamName),
                rank: $entrant->rank,
            );

            foreach ($entrant->memberNames as $memberName) {
                $blader = $this->blader($memberName);

                if (null === $team->addMember($blader)) {
                    continue;
                }

                $this->results->save($this->scoreFor($opened, $blader, $entrant->rank));
            }

            $this->teams->save($team);

            if (!$team->isClaimed()) {
                $this->logger->info('Team entered unclaimed', [
                    'tournament' => $title,
                    'team' => $entrant->teamName,
                    'rank' => $entrant->rank,
                ]);
            }
        }

        /*
         * The roster written out is the one that was read, `bye` included, so
         * a replay drops it again rather than inheriting a file somebody has
         * already tidied.
         */
        $this->flusher->flushThen(
            fn () => $this->writeTeamRecoveryArtifacts(
                title: $title,
                heldOn: $opened->getHeldOn(),
                seasonSlug: $seasonSlug,
                teams: $teams,
                challongeUrl: $challongeUrl,
                sourceFilePath: $sourceFilePath,
            ),
        );

        return TournamentImportResult::Imported;
    }

    /**
     * The lines of a roster that are actually entrants.
     *
     * Public because the command that reads the file summarises what it
     * imported, and counting teams is only right if it counts them the same
     * way the import does.
     *
     * @param list<TeamPlacement> $teams
     *
     * @return list<TeamPlacement>
     */
    public function entrants(array $teams): array
    {
        return array_values(array_filter(
            $teams,
            fn (TeamPlacement $team): bool => self::BYE !== $this->normaliser->normalise($team->teamName),
        ));
    }

    /**
     * The checks both imports make and the tournament they both open, or the
     * reason neither can start.
     *
     * Nothing is persisted until every reason to refuse has been ruled out, so
     * a rejected import leaves no half-built tournament behind for somebody
     * else's flush to find.
     */
    private function open(
        string $title,
        string $heldOn,
        string $seasonSlug,
        ?string $challongeUrl,
        bool $nothingToScore,
    ): Tournament|TournamentImportResult {
        $date = $this->parseDate(trim($heldOn));

        if (null === $date) {
            $this->logger->warning('Tournament import rejected: malformed date', [
                'heldOn' => $heldOn,
            ]);

            return TournamentImportResult::InvalidDate;
        }

        if ($nothingToScore) {
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

        return $tournament;
    }

    /**
     * @param list<TournamentPlacement> $placements
     */
    private function writeRecoveryArtifacts(
        string $title,
        \DateTimeImmutable $heldOn,
        string $seasonSlug,
        array $placements,
        ?string $challongeUrl,
        ?string $knockoutWinner,
        ?string $sourceFilePath,
    ): void {
        $sourceFilePath ??= $this->importFileWriter->write(
            $title,
            $heldOn,
            $placements,
        );

        $this->ledgerService->logTournamentImport(
            title: $title,
            heldOn: $heldOn->format('Y-m-d'),
            sourceFilePath: $sourceFilePath,
            seasonSlug: $seasonSlug,
            challongeUrl: $challongeUrl,
            knockoutWinner: $knockoutWinner,
        );
    }

    /**
     * @param list<TeamPlacement> $teams
     */
    private function writeTeamRecoveryArtifacts(
        string $title,
        \DateTimeImmutable $heldOn,
        string $seasonSlug,
        array $teams,
        ?string $challongeUrl,
        ?string $sourceFilePath,
    ): void {
        $sourceFilePath ??= $this->importFileWriter->writeTeams(
            $title,
            $heldOn,
            $teams,
        );

        $this->ledgerService->logTournamentImport(
            title: $title,
            heldOn: $heldOn->format('Y-m-d'),
            sourceFilePath: $sourceFilePath,
            seasonSlug: $seasonSlug,
            challongeUrl: $challongeUrl,
            teamEvent: true,
        );
    }

    private function buildResult(
        Tournament $tournament,
        TournamentPlacement $placement,
        int $rank,
        ?string $knockoutWinner,
    ): TournamentResult {
        $bonusPoints = $placement->bonusPoints;

        if ($this->hasWonKnockout($placement->playerName, $knockoutWinner)) {
            $bonusPoints += self::KNOCKOUT_WINNER_BONUS;
        }

        return $this->scoreFor(
            $tournament,
            $this->blader($placement->playerName),
            $rank,
            $bonusPoints,
        );
    }

    private function scoreFor(
        Tournament $tournament,
        Player $player,
        int $rank,
        int $bonusPoints = 0,
    ): TournamentResult {
        $result = new TournamentResult();
        $result->setTournament($tournament);
        $result->setPlayer($player);
        $result->setRank($rank);
        $result->setF1Points($this->f1Points->forRank($rank));
        $result->setBonusPoints($bonusPoints);

        return $result;
    }

    /**
     * The blader a list names, invented if the league has never heard of them.
     *
     * A team roster gets the same treatment as a placement list on purpose:
     * both are typed out alongside the event they describe, and three of the
     * bladers in the 11 July rosters had not appeared anywhere before it. The
     * rule that a name nobody recognises is a question rather than a new row
     * belongs to the import that reads a bracket, and #54 is where these
     * commands stop inventing people at all.
     */
    private function blader(string $name): Player
    {
        $player = $this->players->findByName($name);

        if (null !== $player) {
            return $player;
        }

        $player = new Player();
        $player->setName(trim($name));
        $this->players->save($player);

        $this->logger->info('New player record generated', [
            'name' => $player->getName(),
        ]);

        return $player;
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
