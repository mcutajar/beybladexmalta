<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\PreparedTeamImport;
use App\Dto\TeamImportOutcome;
use App\Dto\TeamPlacement;
use App\Dto\TournamentImportOutcome;
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
    /**
     * Public because the import preview shows the bonus applied before
     * anything is written, and two copies of the number are two chances for
     * the screen and the import to disagree about what an event paid out.
     */
    public const int KNOCKOUT_WINNER_BONUS = 10;

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
        private PlayerSlugs $slugs,
    ) {
    }

    /**
     * Imports an ordered placement list, awarding F1 points by finishing rank.
     *
     * @param ?string                   $seasonSlug     null for an unranked event: the tournament is
     *                                                  opened with no season and **no** `TournamentResult`
     *                                                  row is written for any placement
     * @param list<TournamentPlacement> $placements     in finishing order, best first
     * @param ?string                   $sourceFilePath the replayable placement file, generated when absent
     */
    public function import(
        string $title,
        string $heldOn,
        ?string $seasonSlug,
        array $placements,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
        ?string $sourceFilePath = null,
        ?string $snapshotPath = null,
    ): TournamentImportResult {
        return $this->importWithTournament(
            title: $title,
            heldOn: $heldOn,
            seasonSlug: $seasonSlug,
            placements: $placements,
            challongeUrl: $challongeUrl,
            knockoutWinner: $knockoutWinner,
            sourceFilePath: $sourceFilePath,
            snapshotPath: $snapshotPath,
        )->result;
    }

    /**
     * The web flows need the exact event they have just opened so they can
     * continue to its public page without guessing from a non-unique title.
     *
     * @param ?string                   $seasonSlug null for an unranked event
     * @param list<TournamentPlacement> $placements
     */
    public function importWithTournament(
        string $title,
        string $heldOn,
        ?string $seasonSlug,
        array $placements,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
        ?string $sourceFilePath = null,
        ?string $snapshotPath = null,
    ): TournamentImportOutcome {
        $opened = $this->prepare($title, $heldOn, $seasonSlug, $placements, $challongeUrl, $knockoutWinner);

        if ($opened instanceof TournamentImportResult) {
            return new TournamentImportOutcome($opened);
        }

        $title = trim($title);
        $seasonSlug = $this->scope($seasonSlug);

        $this->flusher->flushThen(
            fn () => $this->writeRecoveryArtifacts(
                title: $title,
                heldOn: $opened->getHeldOn(),
                seasonSlug: $seasonSlug,
                placements: $placements,
                challongeUrl: $challongeUrl,
                knockoutWinner: $knockoutWinner,
                sourceFilePath: $sourceFilePath,
                snapshotPath: $snapshotPath,
            ),
        );

        return new TournamentImportOutcome(TournamentImportResult::Imported, $opened);
    }

    /**
     * Stages a solo tournament and its scoring rows without flushing.
     *
     * **The invariant lives here.** A tournament scores if and only if it
     * belongs to a season, so an unranked event returns after `open()` with
     * every entrant archived and not one `TournamentResult` written. Not a
     * zero-point row either: `getLeagueLeaderboard()` counts rows against each
     * blader's best fourteen, and a row paying nothing would displace one that
     * pays.
     *
     * The placement list still travels, because it is what the replayable
     * `var/data/imports/*.txt` holds and what `--unranked` reads back. Nothing
     * scores it — and nothing invents a blader from it either. A ranked import
     * creates one for every unknown name because it has to write a result
     * against somebody; an unranked import writes no result and so has no
     * reason to, which leaves the rule that a name nobody recognises is a
     * question rather than a new row intact. The bladers of an unranked event
     * come from the `app:create-blader` and `app:alias` lines the preview
     * appends before it, and the archive after it is what attaches its
     * entrants to them.
     *
     * @param ?string                   $seasonSlug null for an unranked event
     * @param list<TournamentPlacement> $placements
     */
    public function prepare(
        string $title,
        string $heldOn,
        ?string $seasonSlug,
        array $placements,
        ?string $challongeUrl = null,
        ?string $knockoutWinner = null,
    ): Tournament|TournamentImportResult {
        $title = trim($title);
        $seasonSlug = $this->scope($seasonSlug);

        $opened = $this->open($title, $heldOn, $seasonSlug, $challongeUrl, [] === $placements);

        if ($opened instanceof TournamentImportResult) {
            return $opened;
        }

        if (!$opened->isRanked()) {
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

        return $opened;
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
     * **A blader in two entrants keeps both places and is scored once, at the
     * better of the two ranks.** It is not supposed to happen and the league
     * does not sanction it, but the roster is the record of who played with
     * whom and dropping half of it would lose that; awarding both would pay
     * somebody twice for one evening. The command says whose name it was.
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
    ): TeamImportOutcome {
        // A 2v2 event is ranked-only: its rosters are typed by hand and the
        // finishing order is the only thing the league can use from it.
        $prepared = $this->prepareTeamEvent($title, $heldOn, $seasonSlug, $teams, $challongeUrl);

        if (!$prepared instanceof PreparedTeamImport) {
            return TeamImportOutcome::refused($prepared);
        }

        $title = trim($title);
        $seasonSlug = trim($seasonSlug);

        $this->flusher->flushThen(
            fn () => $this->writeTeamRecoveryArtifacts(
                title: $title,
                heldOn: $prepared->tournament->getHeldOn(),
                seasonSlug: $seasonSlug,
                teams: $teams,
                challongeUrl: $challongeUrl,
                sourceFilePath: $sourceFilePath,
            ),
        );

        return $prepared->outcome;
    }

    /**
     * Stages a team tournament, roster and scoring rows without flushing.
     *
     * @param list<TeamPlacement> $teams
     */
    public function prepareTeamEvent(
        string $title,
        string $heldOn,
        string $seasonSlug,
        array $teams,
        ?string $challongeUrl = null,
    ): PreparedTeamImport|TournamentImportResult {
        $title = trim($title);
        $seasonSlug = trim($seasonSlug);

        $entrants = $this->entrants($teams);

        $opened = $this->open($title, $heldOn, $seasonSlug, $challongeUrl, [] === $entrants);

        if ($opened instanceof TournamentImportResult) {
            return $opened;
        }

        /**
         * Who is to be scored and at what rank, keyed the way the league
         * identifies a blader — `PlayerRepository::findByName()` compares
         * case-folded, so this key reaches the same person that does, and two
         * spellings of somebody the league has never heard of resolve to one
         * new row rather than to two the unique index would reject.
         *
         * @var array<string, array{blader: Player, rank: int}> $scoring
         */
        $scoring = [];
        $inTwoTeams = [];

        foreach ($entrants as $entrant) {
            $team = new TournamentTeam(
                tournament: $opened,
                name: $entrant->teamName,
                normalised: $this->normaliser->normalise($entrant->teamName),
                rank: $entrant->rank,
            );

            foreach ($entrant->memberNames as $memberName) {
                $key = mb_strtolower(trim($memberName));
                $blader = $scoring[$key]['blader'] ?? $this->blader($memberName);

                if (null === $team->addMember($blader)) {
                    continue;
                }

                if (isset($scoring[$key])) {
                    $inTwoTeams[] = $blader->getName();

                    $this->logger->warning('Blader entered in more than one team', [
                        'tournament' => $title,
                        'blader' => $blader->getName(),
                        'team' => $entrant->teamName,
                        'scoredAt' => $scoring[$key]['rank'],
                    ]);
                }

                $scoring[$key] = [
                    'blader' => $blader,
                    'rank' => min($entrant->rank, $scoring[$key]['rank'] ?? $entrant->rank),
                ];
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

        foreach ($scoring as $scored) {
            $this->results->save($this->scoreFor($opened, $scored['blader'], $scored['rank']));
        }

        return new PreparedTeamImport(
            tournament: $opened,
            outcome: TeamImportOutcome::imported(
                teams: count($entrants),
                placements: count($scoring),
                unclaimed: array_values(array_map(
                    static fn (TeamPlacement $team): string => $team->teamName,
                    array_filter($entrants, static fn (TeamPlacement $team): bool => $team->isUnclaimed()),
                )),
                inTwoTeams: array_values(array_unique($inTwoTeams)),
            ),
        );
    }

    /**
     * The lines of a roster that are actually entrants.
     *
     * @param list<TeamPlacement> $teams
     *
     * @return list<TeamPlacement>
     */
    private function entrants(array $teams): array
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
        ?string $seasonSlug,
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

        $season = null;

        if (null !== $seasonSlug) {
            $season = $this->seasons->findBySlug($seasonSlug);

            if (null === $season) {
                $this->logger->error('Season not found', [
                    'slug' => $seasonSlug,
                ]);

                return TournamentImportResult::SeasonNotFound;
            }
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
        ?string $seasonSlug,
        array $placements,
        ?string $challongeUrl,
        ?string $knockoutWinner,
        ?string $sourceFilePath,
        ?string $snapshotPath = null,
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
            snapshotPath: $snapshotPath,
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
        $this->slugs->assign($player);
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
     * The season slug, trimmed, with "unranked" left as null.
     *
     * Trimming a null is not the same as trimming an empty string, and the two
     * must not converge: `''` is a slug nothing answers to and is refused as
     * `SeasonNotFound`, while `null` is a deliberate choice to score nothing.
     */
    private function scope(?string $seasonSlug): ?string
    {
        return null === $seasonSlug ? null : trim($seasonSlug);
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
