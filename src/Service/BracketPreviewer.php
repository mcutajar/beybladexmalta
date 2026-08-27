<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AliasIndex;
use App\Dto\BracketAnswers;
use App\Dto\BracketChoices;
use App\Dto\BracketDecision;
use App\Dto\BracketPlacement;
use App\Dto\BracketPreview;
use App\Dto\BracketPreviewResult;
use App\Dto\ChallongePlacing;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Repository\PlayerRepositoryInterface;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Turns a captured bracket into the screen somebody approves, and writes
 * nothing at all.
 *
 * It is the whole of the preview: the counts that prove the right bracket was
 * fetched, every name the league cannot read, the finishing order with the F1
 * matrix already applied, what the archive will hold, and the exact lines that
 * will land in `repeat.sh`. All of it derived from the snapshot on every
 * request, answers included — so the render that refuses a confirm and the
 * render that offers one are the same code, and neither can disagree with the
 * import that follows.
 *
 * Two rules it exists to keep:
 *
 * 1. **An unreadable name is a question, not a guess.** Suggestions are
 *    offered and never applied. A spelling more than one blader already
 *    answers to gets no answer at all, because no alias can settle it.
 * 2. **The ledger line is borrowed, not composed.** `LedgerService` owns the
 *    command strings, and the preview shows what that class will actually
 *    write rather than a second rendering of the same idea.
 */
class BracketPreviewer
{
    /**
     * Challonge's own filler entrant, dropped here for the same reason the
     * import drops it: it is a slot in a bracket rather than somebody who
     * turned up, so it is never a question and never a placement.
     */
    private const string BYE = 'bye';

    /**
     * @var ?array<int, string>
     */
    private ?array $bladerNames = null;

    public function __construct(
        private ChallongeStandingsResolver $standings,
        private AliasResolver $aliases,
        private AliasNormaliser $normaliser,
        private ChallongeEventFinder $events,
        private ChallongeSnapshotFiles $snapshotFiles,
        private ImportFileWriter $importFiles,
        private LedgerService $ledgerService,
        private F1Points $f1Points,
        private PlayerRepositoryInterface $players,
        private KernelInterface $kernel,
    ) {
    }

    public function preview(
        ChallongeSnapshot $snapshot,
        string $challongeUrl,
        string $title,
        string $heldOn,
        string $seasonSlug,
        BracketChoices $choices = new BracketChoices(),
    ): BracketPreview {
        $bracketUrl = trim($challongeUrl);
        $refusal = $this->refuse($snapshot);

        if (null !== $refusal) {
            return BracketPreview::refused($refusal, $snapshot->slug, $bracketUrl);
        }

        $order = $this->standings->finishingOrder($snapshot);
        $entrants = $this->entrants($snapshot, $order);

        /** @var array<string, array{name: string, blader: ?string, isNew: bool, dropped: bool}> $readings */
        $readings = [];
        $decisions = [];

        $index = $this->aliases->index();

        foreach ($entrants as $normalised => $entrant) {
            [$reading, $decision] = $this->read($index, $normalised, $entrant, $choices->answers);

            $readings[$normalised] = $reading;

            if (null !== $decision) {
                $decisions[] = $decision;
            }
        }

        $placements = $this->placements($snapshot, $order, $readings, $choices);
        $importPath = $this->importFiles->pathFor($title, $this->heldOn($heldOn));

        return BracketPreview::ready(
            slug: $snapshot->slug,
            bracketUrl: $bracketUrl,
            shape: $this->shape($snapshot),
            title: $title,
            heldOn: $heldOn,
            seasonSlug: $seasonSlug,
            participants: $snapshot->participantCount(),
            matches: $snapshot->matchCount(),
            pointsScored: $snapshot->pointsScored(),
            decisions: $decisions,
            placements: $placements,
            archive: $this->archiveSummary($snapshot),
            artifacts: $this->artifacts($snapshot, $importPath),
            ledger: $this->ledger(
                snapshot: $snapshot,
                decisions: $decisions,
                placements: $placements,
                bracketUrl: $bracketUrl,
                title: $title,
                heldOn: $heldOn,
                seasonSlug: $seasonSlug,
                importPath: $importPath,
            ),
        );
    }

    /**
     * The blader the bracket says won the cut, when the league knows them.
     *
     * Public because the import service seeds its own knockout argument from
     * the same answer rather than re-deriving it.
     */
    public function detectedKnockoutWinner(ChallongeSnapshot $snapshot): ?string
    {
        $winner = $snapshot->knockoutWinner();

        if (null === $winner) {
            return null;
        }

        return $this->aliases->resolve($winner->name)->player?->getName();
    }

    private function refuse(ChallongeSnapshot $snapshot): ?BracketPreviewResult
    {
        if ($snapshot->isTeamTournament) {
            return BracketPreviewResult::TeamEvent;
        }

        if (!$snapshot->hasStandings()) {
            return BracketPreviewResult::NoStandings;
        }

        if ([] !== $this->events->forSlug($snapshot->slug)) {
            return BracketPreviewResult::AlreadyImported;
        }

        return null;
    }

    /**
     * Every name the bracket used, and what is known about whoever bore it.
     *
     * The universe is the entrant lists of every stage *and* the standings
     * rows, because the two are not the same set: a row that joined to no
     * entrant carries a name of its own, and a name nowhere in the entrant
     * lists would otherwise never be asked about. Keyed by the normalised
     * spelling, so the group stage and the cut — which give the same blader
     * two unrelated ids — are one question.
     *
     * @param list<ChallongePlacing> $order
     *
     * @return array<string, array{name: string, rank: ?int, matches: int, account: ?string}>
     */
    private function entrants(ChallongeSnapshot $snapshot, array $order): array
    {
        $played = $this->matchesPlayed($snapshot);
        $entrants = [];

        foreach ($order as $placing) {
            $name = $placing->name();

            if (null === $name || '' === trim($name)) {
                continue;
            }

            $normalised = $this->normaliser->normalise($name);

            $entrants[$normalised] ??= [
                'name' => $name,
                'rank' => $placing->rank(),
                'matches' => 0,
                'account' => $placing->standing->challongeUser,
            ];
        }

        foreach ($snapshot->stages as $stage) {
            foreach ($stage->participants as $participant) {
                $normalised = $this->normaliser->normalise($participant->name);

                $entrants[$normalised] ??= [
                    'name' => $participant->name,
                    'rank' => null,
                    'matches' => 0,
                    'account' => null,
                ];

                $entrants[$normalised]['matches'] += $played[$participant->id] ?? 0;
            }
        }

        return $entrants;
    }

    /**
     * How many matches each entrant id played, across every stage.
     *
     * @return array<int, int>
     */
    private function matchesPlayed(ChallongeSnapshot $snapshot): array
    {
        $played = [];

        foreach ($snapshot->stages as $stage) {
            foreach ($stage->playedMatches() as $match) {
                foreach ([$match->player1Id, $match->player2Id] as $id) {
                    if (null !== $id) {
                        $played[$id] = ($played[$id] ?? 0) + 1;
                    }
                }
            }
        }

        return $played;
    }

    /**
     * What the league makes of one spelling, and the question it leaves.
     *
     * @param array{name: string, rank: ?int, matches: int, account: ?string} $entrant
     *
     * @return array{array{name: string, blader: ?string, isNew: bool, dropped: bool}, ?BracketDecision}
     */
    private function read(
        AliasIndex $index,
        string $normalised,
        array $entrant,
        BracketAnswers $answers,
    ): array {
        $name = $entrant['name'];

        if (self::BYE === $normalised) {
            return [['name' => $name, 'blader' => null, 'isNew' => false, 'dropped' => true], null];
        }

        $resolution = $this->aliases->resolveWith($index, $name, $entrant['account']);

        if ($resolution->isResolved()) {
            return [
                ['name' => $name, 'blader' => $resolution->player?->getName(), 'isNew' => false, 'dropped' => false],
                null,
            ];
        }

        /*
         * A collision is refused rather than answered. The spelling already
         * reaches more than one blader, so an alias cannot point it anywhere:
         * AliasService will not file one onto a blader's own name, and picking
         * a side would split somebody's career across two rows in silence.
         */
        $decision = new BracketDecision(
            key: $normalised,
            name: $name,
            isCollision: $resolution->isAmbiguous(),
            problem: $resolution->problem(),
            suggestions: $resolution->suggestions,
            rank: $entrant['rank'],
            matches: $entrant['matches'],
            answer: $resolution->isAmbiguous() ? '' : $answers->for($normalised),
        );

        /*
         * The decision's answer rather than the posted one, because a question
         * with nothing close to it arrives already answered — the placement it
         * produces has to agree with the row the screen renders.
         */
        $answer = $decision->answer;
        $blader = $this->bladerNamed($answer);

        $reading = match (true) {
            null !== $blader => ['name' => $name, 'blader' => $blader, 'isNew' => false, 'dropped' => false],
            BracketAnswers::CREATE === $answer => ['name' => $name, 'blader' => trim($name), 'isNew' => true, 'dropped' => false],
            BracketAnswers::DROP === $answer => ['name' => $name, 'blader' => null, 'isNew' => false, 'dropped' => true],
            default => ['name' => $name, 'blader' => null, 'isNew' => false, 'dropped' => false],
        };

        return [$reading, $decision];
    }

    /**
     * The blader an answer points at, when it points at one that exists.
     *
     * A posted id that names nobody is treated as no answer at all rather than
     * as an error: the decision goes back on the screen unanswered, which is
     * the same refusal as never having chosen.
     */
    private function bladerNamed(string $answer): ?string
    {
        $id = BracketAnswers::bladerId($answer);

        return null === $id ? null : ($this->bladerNames()[$id] ?? null);
    }

    /**
     * Every blader, by id.
     *
     * Read once per request rather than once per decision: a bracket arrives
     * with a median of four questions and a worst case of ten, and each of
     * them would otherwise walk the whole table.
     *
     * @return array<int, string>
     */
    private function bladerNames(): array
    {
        if (null !== $this->bladerNames) {
            return $this->bladerNames;
        }

        $names = [];

        foreach ($this->players->findAll() as $player) {
            $id = $player->getId();

            if (null !== $id) {
                $names[$id] = $player->getName();
            }
        }

        return $this->bladerNames = $names;
    }

    /**
     * The finishing order the league will award, in the order it will award it.
     *
     * @param list<ChallongePlacing>                                                          $order
     * @param array<string, array{name: string, blader: ?string, isNew: bool, dropped: bool}> $readings
     *
     * @return list<BracketPlacement>
     */
    private function placements(
        ChallongeSnapshot $snapshot,
        array $order,
        array $readings,
        BracketChoices $choices,
    ): array {
        $knockout = $choices->knockoutWinner ?? $this->detectedKnockoutWinner($snapshot);
        $rows = [];

        foreach ($order as $row => $placing) {
            $name = $placing->name();

            if (null === $name || '' === trim($name)) {
                continue;
            }

            $reading = $readings[$this->normaliser->normalise($name)] ?? null;

            if (null === $reading || $reading['dropped']) {
                continue;
            }

            $rows[] = [
                'place' => $choices->placeOf($row, $placing->rank()),
                'row' => $row,
                'placing' => $placing,
                'name' => $name,
                'reading' => $reading,
            ];
        }

        /*
         * Stable on the row it came from, so an order somebody only partly
         * retyped keeps the bracket's own sequence for everything they left
         * alone.
         */
        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['place'], $a['row']] <=> [$b['place'], $b['row']],
        );

        $placements = [];
        $position = 0;

        foreach ($rows as $row) {
            ++$position;
            $blader = $row['reading']['blader'];
            $wonTheKnockout = null !== $blader
                && null !== $knockout
                && '' !== trim($knockout)
                && 0 === strcasecmp($blader, trim($knockout));

            $placements[] = new BracketPlacement(
                position: $position,
                row: $row['row'],
                challongeRank: $row['placing']->rank(),
                challongeName: $row['name'],
                bladerName: $blader,
                isNewBlader: $row['reading']['isNew'],
                f1Points: $this->f1Points->forRank($position),
                bonusPoints: $wonTheKnockout ? TournamentImportService::KNOCKOUT_WINNER_BONUS : 0,
                wonTheKnockout: $wonTheKnockout,
            );
        }

        return $placements;
    }

    /**
     * What was fetched, in three words: "Swiss + cut", "Round robin".
     */
    private function shape(ChallongeSnapshot $snapshot): string
    {
        $parts = array_map(
            static fn (ChallongeStage $stage): string => ucfirst(str_replace('_', ' ', $stage->format)),
            $snapshot->stages,
        );

        return implode(' + ', $parts);
    }

    /**
     * What the archive will hold, stage by stage.
     *
     * The receipt for the half of the import that is not scoring: 951 matches
     * across the corpus against 220 placement rows, and none of it visible on
     * the leaderboard either before or after.
     *
     * @return list<array{label: string, note: string, value: string}>
     */
    private function archiveSummary(ChallongeSnapshot $snapshot): array
    {
        $rows = [];

        foreach ($snapshot->stages as $stage) {
            $rows[] = [
                'label' => match ($stage->kind) {
                    ChallongeStageKind::Group => 'Group stage',
                    ChallongeStageKind::Final => 'Top cut',
                    ChallongeStageKind::Single => 'The whole event',
                },
                'note' => trim(sprintf(
                    '%s%s · %d %s · %d %s',
                    null === $stage->name ? '' : $stage->name.' · ',
                    $stage->format,
                    count($stage->rounds),
                    1 === count($stage->rounds) ? 'round' : 'rounds',
                    count($stage->participants),
                    1 === count($stage->participants) ? 'entrant' : 'entrants',
                )),
                'value' => sprintf(
                    '%d %s',
                    count($stage->matches),
                    1 === count($stage->matches) ? 'match' : 'matches',
                ),
            ];
        }

        $rows[] = [
            'label' => 'Standings rows',
            'note' => 'column for column, as Challonge printed them',
            'value' => (string) $snapshot->standingsCount(),
        ];

        $rows[] = [
            'label' => 'Byes and forfeits',
            'note' => 'kept, rendered nowhere yet',
            'value' => sprintf('%d · %d', $snapshot->byeCount(), $snapshot->forfeitedMatchCount()),
        ];

        return $rows;
    }

    /**
     * The files a confirm writes, named the way somebody reading `git status`
     * will see them.
     *
     * @return list<array{verb: string, path: string}>
     */
    private function artifacts(ChallongeSnapshot $snapshot, string $importPath): array
    {
        $snapshotPath = $this->snapshotFiles->pathFor($snapshot->slug);

        return [
            [
                'verb' => is_file($snapshotPath) ? 'replace' : 'write',
                'path' => $this->relative($snapshotPath),
            ],
            [
                'verb' => is_file($importPath) ? 'replace' : 'write',
                'path' => $this->relative($importPath),
            ],
            [
                'verb' => 'append',
                'path' => 'var/log/command_ledger.sh',
            ],
        ];
    }

    /**
     * Every line a confirm appends to the ledger, in the order it replays.
     *
     * A blader is created before the import that scores them and before the
     * alias that points at them, an alias before the import that spells its
     * blader, and the archive after the import it is written against.
     *
     * @param list<BracketDecision>  $decisions
     * @param list<BracketPlacement> $placements
     *
     * @return list<string>
     */
    private function ledger(
        ChallongeSnapshot $snapshot,
        array $decisions,
        array $placements,
        string $bracketUrl,
        string $title,
        string $heldOn,
        string $seasonSlug,
        string $importPath,
    ): array {
        $lines = [];

        foreach ($decisions as $decision) {
            if (BracketAnswers::CREATE === $decision->answer) {
                $lines[] = $this->ledgerService->createBladerCommand(trim($decision->name));
            }

            $blader = $this->bladerNamed($decision->answer);

            if (null !== $blader) {
                $lines[] = $this->ledgerService->aliasAddedCommand($blader, $decision->name);
            }
        }

        $winner = null;

        foreach ($placements as $placement) {
            if ($placement->wonTheKnockout) {
                $winner = $placement->bladerName;
            }
        }

        $lines[] = $this->ledgerService->tournamentImportCommand(
            title: $title,
            heldOn: $heldOn,
            sourceFilePath: $importPath,
            seasonSlug: $seasonSlug,
            challongeUrl: $bracketUrl,
            knockoutWinner: $winner,
        );

        $lines[] = $this->ledgerService->challongeArchiveCommand($snapshot->slug);

        return $lines;
    }

    /**
     * The date, or today when what was typed is not one.
     *
     * Only ever used to name the import file in the preview. The import itself
     * refuses a malformed date outright, and the screen in front of this one
     * checks the format before anything is fetched.
     */
    private function heldOn(string $heldOn): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', trim($heldOn));

        return false === $date ? new \DateTimeImmutable('today') : $date;
    }

    private function relative(string $path): string
    {
        $root = $this->kernel->getProjectDir().'/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}
