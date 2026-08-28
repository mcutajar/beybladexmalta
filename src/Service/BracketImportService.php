<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\BracketAnswers;
use App\Dto\BracketDecision;
use App\Dto\BracketImportOutcome;
use App\Dto\BracketPreview;
use App\Dto\ChallongeArchiveOutcome;
use App\Dto\ChallongeSnapshot;
use App\Dto\TournamentPlacement;
use App\Entity\Player;
use App\Repository\PlayerRepositoryInterface;
use App\Repository\SeasonRepository;
use Psr\Log\LoggerInterface;

/**
 * Everything a confirmed preview writes, in the order a replay would.
 *
 * The screen is the decision and this is the consequence: bladers somebody
 * said were new, aliases somebody said pointed at a blader already on record,
 * the snapshot, the tournament with its ten scoring results, and the archive
 * of every match the bracket holds. Five writes, each of which appends its own
 * replayable line, which is why they happen in this order — a blader before
 * the alias that spells them and the import that scores them, the import
 * before the archive written against it.
 *
 * **It rebuilds the preview rather than trusting one.** The screen posts back
 * exactly one kind of thing and it is not a fact: which blader each unreadable
 * name is. Everything else — the bracket, the counts, the finishing order, who
 * won the cut, the points those answers produce — is derived here from the
 * snapshot the server kept. So there is nothing to sign and nothing a tampered
 * field could assert.
 *
 * **Nothing is written until every unresolved name is answered.** That is the
 * rule the whole epic turns on, and it is checked against the rebuilt preview
 * rather than against what the browser claimed.
 */
class BracketImportService
{
    /**
     * The F1 matrix stops at ten, and so does the placement list.
     *
     * Everyone below eleventh is archived and unscored — half the matches in
     * the corpus and no league points at all. Writing them as results would
     * put rows in `tournament_results` that `getLeagueLeaderboard()` counts
     * against each blader's best fourteen, which is the one thing this whole
     * pipeline is not allowed to change.
     */
    private const int SCORING_PLACES = 10;

    public function __construct(
        private BracketPreviewer $previewer,
        private TournamentImportService $imports,
        private ChallongeArchiveService $archive,
        private ChallongeSnapshotWriter $snapshotWriter,
        private ChallongeEventFinder $events,
        private AliasService $aliases,
        private AliasRejectionService $rejections,
        private BladerService $bladers,
        private PlayerRepositoryInterface $players,
        private SeasonRepository $seasons,
        private LoggerInterface $logger,
    ) {
    }

    public function apply(
        ChallongeSnapshot $snapshot,
        string $challongeUrl,
        string $title,
        string $heldOn,
        string $seasonSlug,
        BracketAnswers $answers,
    ): BracketImportOutcome {
        $preview = $this->previewer->preview(
            $snapshot,
            $challongeUrl,
            $title,
            $heldOn,
            $seasonSlug,
            $answers,
        );

        $refusal = $this->refuse($preview, $heldOn, $seasonSlug);

        if (null !== $refusal) {
            return BracketImportOutcome::refused($refusal, $preview);
        }

        $created = $this->createBladers($preview);
        $this->recordRejectedSuggestions($preview);
        $problems = $this->fileAliases($preview);

        if ([] !== $problems) {
            return BracketImportOutcome::refused(BracketImportResult::AliasRefused, $preview, $problems);
        }

        $this->snapshotWriter->write($snapshot);

        $placements = $this->scoringPlacements($preview);

        $imported = $this->imports->import(
            title: $title,
            heldOn: $heldOn,
            seasonSlug: $seasonSlug,
            placements: $placements,
            challongeUrl: $preview->bracketUrl,
            knockoutWinner: $preview->knockoutWinner()?->bladerName,
        );

        if (TournamentImportResult::Imported !== $imported) {
            return BracketImportOutcome::refused($this->mapped($imported), $preview);
        }

        return BracketImportOutcome::imported(
            preview: $preview,
            scored: count($placements),
            created: $created,
            seeded: $preview->seeded(),
            aliased: count($this->linked($preview)),
            archive: $this->archiveAgainstTheEvent($snapshot),
        );
    }

    /**
     * Why nothing may be written, checked before anything is.
     *
     * The date and the season are checked here rather than left to the import
     * to reject, because by then a blader would have been invented and an
     * alias filed for a tournament that never opened.
     */
    private function refuse(BracketPreview $preview, string $heldOn, string $seasonSlug): ?BracketImportResult
    {
        if (!$preview->isReady()) {
            return BracketImportResult::Refused;
        }

        if ($preview->isBlocked()) {
            return BracketImportResult::DecisionsOutstanding;
        }

        if (!$this->isADate($heldOn)) {
            return BracketImportResult::InvalidDate;
        }

        if (null === $this->seasons->findBySlug(trim($seasonSlug))) {
            return BracketImportResult::SeasonNotFound;
        }

        if ([] === $this->scoringPlacements($preview)) {
            return BracketImportResult::NoPlacements;
        }

        if ($this->hasDuplicateScoringPlacements($preview)) {
            return BracketImportResult::DuplicatePlacements;
        }

        return null;
    }

    /**
     * The strict YYYY-MM-DD the import accepts and nothing looser, so a
     * mistyped date is rejected here rather than silently reinterpreted two
     * writes later.
     */
    private function isADate(string $heldOn): bool
    {
        $heldOn = trim($heldOn);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $heldOn);

        return false !== $date && $date->format('Y-m-d') === $heldOn;
    }

    /**
     * The bladers somebody said were new.
     *
     * This is the only place in the system that invents one, and it gets a
     * ledger line of its own because most of them will never appear on a
     * placement list: fifty-two of the unresolved spellings across the
     * captured brackets finished eleventh or worse, so they are archived
     * rather than scored, and without a line they would quietly stop existing
     * at the next schema rebuild.
     */
    private function createBladers(BracketPreview $preview): int
    {
        $created = 0;

        foreach ($preview->decisions as $decision) {
            if (BracketAnswers::CREATE !== $decision->answer) {
                continue;
            }

            if (CreateBladerResult::Created !== $this->bladers->create($decision->name)) {
                continue;
            }

            ++$created;

            /*
             * Whether anybody looked. A blader created because nothing in the
             * league came close is the right answer nine times in ten and a
             * duplicate the tenth — `Orteborn` is three edits from `Otrebor`,
             * which is past the suggestion threshold, so the screen offers
             * nothing and the default stands unless somebody recognises it.
             * When that duplicate turns up three brackets later, this line is
             * how you find out that the row was never examined.
             */
            $this->logger->info('Blader created from an import preview', [
                'name' => $decision->name,
                'answer' => $decision->wasSeeded() ? 'taken as the default' : 'chosen',
                'bracket' => $preview->slug,
            ]);
        }

        return $created;
    }

    /** Every displayed candidate the operator's answer ruled out. */
    private function recordRejectedSuggestions(BracketPreview $preview): void
    {
        foreach ($preview->decisions as $decision) {
            foreach ($decision->rejectedSuggestions() as $suggestion) {
                $this->rejections->reject($suggestion->player->getName(), $decision->name);
            }
        }
    }

    /**
     * The spellings somebody pointed at a blader already on record.
     *
     * `AliasService` writes its own ledger line inside its own flush, and it
     * has refusals of its own — a spelling already taken, a blader that has
     * become ambiguous since the preview was rendered. Any of those and the
     * import stops before the tournament is opened, because the name it
     * refused would otherwise reach the import as somebody nobody knows.
     *
     * Those refusals are close to unreachable from this screen: a spelling
     * that was already another blader's name or another blader's alias would
     * have *resolved* rather than become a question. They are here for the
     * case where the league changed underneath a preview somebody left open.
     *
     * When one does fire, the bladers created a moment ago and any aliases
     * already filed stay — each with its own ledger line, so the record is
     * consistent — and the retry is safe: creating a blader who exists is a
     * no-op and re-filing an alias reports itself. What does not exist is the
     * event, which is the part that would have been wrong.
     *
     * @return list<string> empty when every alias was filed
     */
    private function fileAliases(BracketPreview $preview): array
    {
        $problems = [];

        foreach ($this->linked($preview) as $decision) {
            $blader = $this->bladerBehind($decision);

            if (null === $blader) {
                $problems[] = sprintf('"%s" was linked to a blader who is no longer on record.', $decision->name);

                continue;
            }

            $result = $this->aliases->add($blader->getName(), $decision->name);

            /*
             * "It is already their own name" is not a refusal to act on. The
             * alias table and the blader names are one namespace, so a
             * spelling that folds onto the blader it was pointed at is already
             * saying what it was going to say.
             */
            if (in_array($result, [AddAliasResult::Added, AddAliasResult::AlreadyRecorded, AddAliasResult::IsTheirOwnName], true)) {
                continue;
            }

            $problems[] = sprintf(
                '"%s" could not be filed against %s: %s.',
                $decision->name,
                $blader->getName(),
                $this->because($result),
            );
        }

        return $problems;
    }

    /**
     * @return list<BracketDecision>
     */
    private function linked(BracketPreview $preview): array
    {
        return array_values(array_filter(
            $preview->decisions,
            static fn (BracketDecision $decision): bool => null !== BracketAnswers::bladerId($decision->answer),
        ));
    }

    private function bladerBehind(BracketDecision $decision): ?Player
    {
        $id = BracketAnswers::bladerId($decision->answer);

        foreach ($this->players->findAll() as $player) {
            if ($player->getId() === $id) {
                return $player;
            }
        }

        return null;
    }

    private function because(AddAliasResult $result): string
    {
        return match ($result) {
            AddAliasResult::Added, AddAliasResult::AlreadyRecorded => 'it was filed',
            AddAliasResult::IsTheirOwnName => 'it is what that blader is already called',
            AddAliasResult::IsAnotherBladersName => 'it is another blader\'s own name, which is a merge rather than an alias',
            AddAliasResult::TakenByAnotherBlader => 'another blader already answers to it',
            AddAliasResult::BladerNotFound => 'that blader is no longer on record',
            AddAliasResult::BladerIsAmbiguous => 'more than one blader answers to that name',
            AddAliasResult::NotAName => 'there is no name under the punctuation',
        };
    }

    /**
     * The top ten of the previewed order, as the import reads them.
     *
     * The names are the league's own rather than the bracket's, because that
     * is what `var/data/imports/*.txt` has always held and what a replay
     * resolves: handing the import `Anzjan` would invent a blader beside the
     * `Lanzjan` the alias just pointed at.
     *
     * @return list<TournamentPlacement>
     */
    private function scoringPlacements(BracketPreview $preview): array
    {
        $placements = [];

        foreach ($preview->placements as $placement) {
            if ($placement->position > self::SCORING_PLACES || null === $placement->bladerName) {
                continue;
            }

            $placements[] = new TournamentPlacement($placement->bladerName);
        }

        return $placements;
    }

    /**
     * This runs before creating bladers or filing aliases. In particular, two
     * different bracket spellings may both have been linked to one blader;
     * letting either alias persist would make every retry fail the same way.
     */
    private function hasDuplicateScoringPlacements(BracketPreview $preview): bool
    {
        $seen = [];

        foreach ($this->scoringPlacements($preview) as $placement) {
            $name = mb_strtolower(trim($placement->playerName));

            if (isset($seen[$name])) {
                return true;
            }

            $seen[$name] = true;
        }

        return false;
    }

    /**
     * The archive, written against the event the import just created.
     *
     * Found by the bracket rather than carried out of the import, which is the
     * same rule `app:archive-challonge` follows — and safe here because the
     * preview refuses a bracket any event already names, so the import leaves
     * exactly one.
     */
    private function archiveAgainstTheEvent(ChallongeSnapshot $snapshot): ?ChallongeArchiveOutcome
    {
        $events = $this->events->forSlug($snapshot->slug);

        if (1 !== count($events)) {
            $this->logger->error('The imported event could not be found by its bracket, so it was left unarchived.', [
                'slug' => $snapshot->slug,
                'events' => count($events),
            ]);

            return null;
        }

        try {
            return $this->archive->archive($events[0], $snapshot);
        } catch (\Throwable $exception) {
            $this->logger->error('The tournament was imported, but its bracket could not be archived.', [
                'slug' => $snapshot->slug,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    private function mapped(TournamentImportResult $result): BracketImportResult
    {
        return match ($result) {
            TournamentImportResult::Imported => BracketImportResult::Imported,
            TournamentImportResult::InvalidDate => BracketImportResult::InvalidDate,
            TournamentImportResult::SeasonNotFound => BracketImportResult::SeasonNotFound,
            TournamentImportResult::NoPlacements => BracketImportResult::NoPlacements,
        };
    }
}
