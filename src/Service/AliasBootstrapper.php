<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AliasBootstrapOutcome;
use App\Dto\AliasBootstrapPlan;
use App\Dto\AliasContradiction;
use App\Dto\AliasIndex;
use App\Dto\AliasProposal;
use App\Dto\AliasProposalStatus;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeUrl;
use App\Dto\SkippedEvent;
use App\Dto\SkippedEventReason;
use App\Entity\Player;
use App\Entity\PlayerAliasSource;
use App\Entity\Tournament;
use App\Exception\ChallongeSnapshotReadException;
use App\Exception\InvalidChallongeUrlException;
use App\Exception\UnsupportedChallongeBracketException;
use App\Repository\PlayerAliasRepository;
use App\Repository\TournamentRepository;

/**
 * Reads the alias table out of the events that have already been imported.
 *
 * The table would otherwise be an evening of typing, and it does not have to
 * be: **every event already imported is a labelled example.** A placement list
 * was typed by hand under the league's own names, and the bracket it was typed
 * from ranks the same people under whatever they called themselves that night.
 * Rank *n* of the bracket is line *n* of the list — verified position for
 * position across all sixteen non-team events by `CapturedBracketsTest` — so
 * every pairing of a Challonge spelling with a blader is already on record and
 * only needs reading out.
 *
 * Three rules make that safe enough to run in one go:
 *
 * 1. **Nothing is written that two events disagree about.** A spelling that
 *    reaches two bladers is a contradiction and is reported instead, because
 *    picking one would file half of somebody's career under a name that then
 *    resolves silently for ever.
 * 2. **Nothing is written that the alias table already answers.** A spelling
 *    that folds onto the blader's own name is not learned at all, and one
 *    already on file is left alone — which is what makes a second run a no-op.
 * 3. **Nothing is created.** The pass only ever pairs a spelling with a blader
 *    who is already in the league, because the blader came out of the import's
 *    own results. It writes through `AliasService`, which refuses the rest.
 *
 * What it deliberately does not read is a **team event**. Its entrants are
 * teams, a team name belongs to two bladers rather than one, and the lists it
 * was imported from were padded where the roster was not known — `JG1`, `JG2`
 * and the literal `-`, `--` and `---` are rows in `players` and are not
 * people. Pairing a rank with a line there would file a team name against
 * whichever half of the team was written first, and would learn a phantom as
 * though it were a blader. Team rosters belong to #67, which puts an unclaimed
 * team on record and takes those five rows back out.
 */
class AliasBootstrapper
{
    public function __construct(
        private TournamentRepository $tournaments,
        private ChallongeSnapshotFiles $files,
        private ChallongeSnapshotReader $reader,
        private ChallongeStandingsResolver $standings,
        private AliasNormaliser $normaliser,
        private AliasResolver $resolver,
        private PlayerAliasRepository $aliases,
        private AliasService $aliasService,
    ) {
    }

    /**
     * Everything the pass makes of the league's own history, written down
     * before any of it is applied.
     */
    public function plan(): AliasBootstrapPlan
    {
        $events = $this->tournaments->everyEventInOrder();
        $brackets = $this->bracketPerEvent($events);
        $teamEvents = $this->eventsImportedAsTeams($brackets);

        $evidence = [];
        $skipped = [];
        $undecided = [];
        $read = 0;
        $placements = 0;
        $agreed = 0;

        foreach ($events as $event) {
            $slug = $brackets[spl_object_id($event)] ?? null;

            if (null === $slug) {
                $url = $event->getChallongeUrl();

                $skipped[] = new SkippedEvent(
                    $event->getTitle(),
                    null,
                    null === $url || '' === $url ? SkippedEventReason::NoBracket : SkippedEventReason::NotABracketUrl,
                );

                continue;
            }

            if (in_array($slug, $teamEvents, true)) {
                $skipped[] = new SkippedEvent($event->getTitle(), $slug, SkippedEventReason::TeamEvent);

                continue;
            }

            try {
                $snapshot = $this->snapshot($slug);
            } catch (ChallongeSnapshotReadException $exception) {
                $skipped[] = new SkippedEvent($event->getTitle(), $slug, SkippedEventReason::Unreadable, $exception->getMessage());

                continue;
            }

            if (null === $snapshot) {
                $skipped[] = new SkippedEvent($event->getTitle(), $slug, SkippedEventReason::NotCaptured);

                continue;
            }

            /*
             * Believed rather than relied on. The flag is false in all eighteen
             * captured brackets — the module store does not carry it — so today
             * the line above is what tells a team event apart. Respecting it
             * costs nothing and means a bracket that ever does say so is heard.
             */
            if ($snapshot->isTeamTournament) {
                $skipped[] = new SkippedEvent($event->getTitle(), $slug, SkippedEventReason::TeamEvent);

                continue;
            }

            try {
                $ranked = $this->rankedEntrants($snapshot);
            } catch (UnsupportedChallongeBracketException $exception) {
                $skipped[] = new SkippedEvent($event->getTitle(), $slug, SkippedEventReason::Unsupported, $exception->getMessage());

                continue;
            }

            $imported = $this->rankedResults($event);

            if (null === $ranked || null === $imported) {
                $skipped[] = new SkippedEvent($event->getTitle(), $slug, SkippedEventReason::RanksAreNotAnOrder);

                continue;
            }

            if ([] === $ranked) {
                $skipped[] = new SkippedEvent($event->getTitle(), $slug, SkippedEventReason::NoStandings);

                continue;
            }

            ++$read;

            foreach ($imported as $rank => $blader) {
                $spelling = $ranked[$rank] ?? null;

                if (null === $spelling || '' === $this->normaliser->normalise($spelling)) {
                    $undecided[] = sprintf(
                        '%s rank %d was imported as %s, and the bracket ranks %s there.',
                        $event->getTitle(),
                        $rank,
                        $blader->getName(),
                        null === $spelling ? 'nobody' : sprintf('"%s"', $spelling),
                    );

                    continue;
                }

                ++$placements;

                $normalised = $this->normaliser->normalise($spelling);

                /*
                 * The bracket already says what we say. 118 of the 160
                 * placements are this, and an alias for one of them would be a
                 * row the resolver never reads: a spelling that folds onto a
                 * blader's own name resolves without the table.
                 */
                if ($normalised === $this->normaliser->normalise($blader->getName())) {
                    ++$agreed;

                    continue;
                }

                $evidence[$normalised][] = [
                    'spelling' => $spelling,
                    'blader' => $blader,
                    'event' => $event->getTitle(),
                ];
            }
        }

        [$proposals, $contradictions] = $this->weigh($evidence);

        return new AliasBootstrapPlan(
            proposals: $proposals,
            contradictions: $contradictions,
            skipped: $skipped,
            undecided: $undecided,
            events: $read,
            placements: $placements,
            agreed: $agreed,
        );
    }

    /**
     * Files what the plan proposes, one alias at a time through the service
     * that owns the rules.
     *
     * Deliberately not a bulk write. Each row goes through the same checks a
     * typed one does and appends its own ledger line, so a rebuilt database
     * replays the seeding exactly as it replays everything else — and so a row
     * the plan was optimistic about is refused here rather than slipped in
     * behind the others.
     */
    public function apply(AliasBootstrapPlan $plan): AliasBootstrapOutcome
    {
        $written = 0;
        $refused = [];

        foreach ($plan->writable() as $proposal) {
            $result = $this->aliasService->add(
                $proposal->bladerName(),
                $proposal->spelling,
                PlayerAliasSource::Seeded,
            );

            if (AddAliasResult::Added === $result) {
                ++$written;

                continue;
            }

            $refused[] = [
                'spelling' => $proposal->spelling,
                'blader' => $proposal->bladerName(),
                'result' => $result,
            ];
        }

        return new AliasBootstrapOutcome($written, $refused);
    }

    /**
     * The evidence, sorted into what can be written and what cannot.
     *
     * @param array<string, list<array{spelling: string, blader: Player, event: string}>> $evidence
     *
     * @return array{list<AliasProposal>, list<AliasContradiction>}
     */
    private function weigh(array $evidence): array
    {
        $index = $this->resolver->index();
        $proposals = [];
        $contradictions = [];

        foreach ($evidence as $normalised => $sightings) {
            $claims = [];

            foreach ($sightings as $sighting) {
                $claims[$sighting['blader']->getName()][] = $sighting['event'];
            }

            if (count($claims) > 1) {
                $contradictions[] = new AliasContradiction($sightings[0]['spelling'], (string) $normalised, $claims);

                continue;
            }

            $blader = $sightings[0]['blader'];

            $proposals[] = new AliasProposal(
                spelling: $sightings[0]['spelling'],
                normalised: (string) $normalised,
                blader: $blader,
                status: $this->statusOf((string) $normalised, $blader, $index),
                events: $claims[$blader->getName()],
            );
        }

        usort(
            $proposals,
            static fn (AliasProposal $a, AliasProposal $b): int => [$a->bladerName(), $a->spelling] <=> [$b->bladerName(), $b->spelling],
        );

        usort(
            $contradictions,
            static fn (AliasContradiction $a, AliasContradiction $b): int => $a->normalised <=> $b->normalised,
        );

        return [$proposals, $contradictions];
    }

    /**
     * What the tables already say about a spelling the pass has just derived.
     *
     * A spelling that folds onto the blader's own name never gets this far, so
     * anybody the index answers with here is somebody else — which makes the
     * pair a merge to consider rather than an alias to file.
     */
    private function statusOf(string $normalised, Player $blader, AliasIndex $index): AliasProposalStatus
    {
        $existing = $this->aliases->findByNormalised($normalised);

        if (null !== $existing) {
            return $existing->getPlayer() === $blader
                ? AliasProposalStatus::AlreadyOnFile
                : AliasProposalStatus::TakenByAnotherBlader;
        }

        return [] === $index->bladersCalled($normalised)
            ? AliasProposalStatus::Unrecorded
            : AliasProposalStatus::IsAnotherBladersName;
    }

    /**
     * The bracket each event was imported from, by object id so that two
     * tournaments pointing at one bracket stay two events.
     *
     * @param list<Tournament> $events
     *
     * @return array<int, string>
     */
    private function bracketPerEvent(array $events): array
    {
        $brackets = [];

        foreach ($events as $event) {
            $url = $event->getChallongeUrl();

            if (null === $url || '' === $url) {
                continue;
            }

            try {
                $brackets[spl_object_id($event)] = ChallongeUrl::fromString($url)->slug;
            } catch (InvalidChallongeUrlException) {
                continue;
            }
        }

        return $brackets;
    }

    /**
     * The brackets that were imported more than once.
     *
     * That is how a 2v2 event is told apart from the rest, and it is not a
     * heuristic: a team event is one bracket imported twice, once for each
     * half of every team, and `CapturedBracketsTest` holds the corpus to it —
     * those two slugs are the only ones any two import lines share.
     *
     * The snapshot's own `is_team` is consulted as well, at the point of use.
     * It is false in all eighteen captured brackets, the module store not
     * carrying the field, so it settles nothing today; respecting it costs a
     * line and means a bracket that ever does say so is believed. From #67
     * onwards a team event is declared at import and neither signal is needed.
     *
     * @param array<int, string> $brackets
     *
     * @return list<string>
     */
    private function eventsImportedAsTeams(array $brackets): array
    {
        $imports = array_count_values($brackets);

        return array_keys(array_filter(
            $imports,
            static fn (int $times): bool => $times > 1,
        ));
    }

    private function snapshot(string $slug): ?ChallongeSnapshot
    {
        $path = $this->files->pathFor($slug);

        return is_file($path) ? $this->reader->readFile($path) : null;
    }

    /**
     * The bracket's finishing order as rank => spelling, or null when two
     * entrants share a rank.
     *
     * @return array<int, string>|null
     */
    private function rankedEntrants(ChallongeSnapshot $snapshot): ?array
    {
        $ranked = [];

        foreach ($this->standings->finishingOrder($snapshot) as $placing) {
            if (isset($ranked[$placing->rank()])) {
                return null;
            }

            $name = $placing->name();

            if (null !== $name) {
                $ranked[$placing->rank()] = $name;
            }
        }

        return $ranked;
    }

    /**
     * What the import recorded, as rank => blader, or null when two results
     * share a rank.
     *
     * @return array<int, Player>|null
     */
    private function rankedResults(Tournament $event): ?array
    {
        $imported = [];

        foreach ($event->getResults() as $result) {
            if (isset($imported[$result->getRank()])) {
                return null;
            }

            $imported[$result->getRank()] = $result->getPlayer();
        }

        ksort($imported);

        return $imported;
    }
}
