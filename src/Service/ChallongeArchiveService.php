<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\AliasIndex;
use App\Dto\ChallongeArchiveOutcome;
use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeRecord;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStanding;
use App\Dto\ChallongeUrl;
use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;
use App\Exception\InvalidChallongeUrlException;
use App\Repository\TournamentStageRepository;
use Psr\Log\LoggerInterface;

/**
 * Writes everything a captured bracket knows into the database: its stages,
 * every entrant, every match, and the per-game scorelines of the matches that
 * had more than one.
 *
 * The site stores placements only. The same eighteen events hold nine hundred
 * and fifty-one matches, and everything that makes a profile worth reading —
 * match history, head-to-heads, streaks, how a win was finished — is in the
 * part that was thrown away. This is where it stops being thrown away.
 *
 * **Nothing here scores anything.** `TournamentResult` is untouched, with the
 * same ranks and the same matrix, so `PlayerRepository::getLeagueLeaderboard()`
 * returns exactly what it returned before an archive ran. The archive is
 * additive, and a tournament that has never been archived is not a broken one.
 *
 * **Everyone is archived, not just the ten who scored.** Ranks below eleven
 * pay no league points and are half the matches; a blader's record is wrong
 * without them.
 *
 * **Running it twice writes the same rows.** Every level has a natural key —
 * a stage is its position, an entrant is their Challonge id within the stage,
 * a match is its Challonge id within the tournament, a game is its number
 * within the match — and each is looked up before it is written. Rows the
 * bracket no longer has are dropped rather than left behind, so re-archiving
 * an edited bracket repairs the record instead of layering a second copy over
 * it. That is deliberate: `app:import-tournament` has no such guard, which is
 * why a second replay of `repeat.sh` silently doubles every result it holds.
 *
 * A name that reaches no blader is archived under the spelling the bracket
 * used, attached to nobody. This never creates a player and never guesses one:
 * an unrecognised entrant is a missing alias, and re-archiving after somebody
 * files it picks them up.
 */
class ChallongeArchiveService
{
    /**
     * Challonge's own filler entrant. It is a slot rather than somebody who
     * turned up, so it is transcribed like any other row of the bracket and
     * resolved to nobody — and, unlike a name we simply do not know, it is not
     * reported as one worth an alias.
     *
     * The import drops it outright, because a placement list is about who
     * scored. An archive is about what the bracket said.
     */
    private const string BYE = 'bye';

    /**
     * Challonge's badge on the entrants who went through to the cut.
     */
    private const string ADVANCED = 'advanced';

    public function __construct(
        private TournamentStageRepository $stages,
        private ChallongeStandingsResolver $standings,
        private ChallongeRecordReader $records,
        private AliasResolver $aliases,
        private AliasNormaliser $normaliser,
        private LedgerService $ledgerService,
        private FlusherInterface $flusher,
        private LoggerInterface $logger,
    ) {
    }

    public function archive(Tournament $tournament, ChallongeSnapshot $snapshot): ChallongeArchiveOutcome
    {
        $refusal = $this->refuse($tournament, $snapshot);

        if (null !== $refusal) {
            return ChallongeArchiveOutcome::refused($refusal);
        }

        $tally = new ChallongeArchiveTally();
        $index = $this->aliases->index();

        $keptStages = [];
        $keptMatches = [];

        foreach ($this->stages->forTournament($tournament) as $stage) {
            $keptStages[$stage->getPosition()] = $stage;

            foreach ($stage->getMatches() as $match) {
                $keptMatches[$match->getChallongeId()] = $match;
            }
        }

        foreach ($snapshot->stages as $position => $stage) {
            $entity = $keptStages[$position] ?? new TournamentStage($tournament, $position, $stage->kind);
            unset($keptStages[$position]);

            $entity->transcribe($stage->kind, $stage->name, $stage->format, count($stage->rounds));

            $this->archiveMatches(
                $entity,
                $stage,
                $this->archiveEntrants($entity, $stage, $index, $tally),
                $keptMatches,
                $tally,
            );

            $this->stages->save($entity);
            ++$tally->stages;
        }

        /*
         * A stage the bracket no longer has takes its entrants and matches
         * with it, so its matches are struck off the leftovers first: they are
         * being dropped once, not twice.
         */
        foreach ($keptStages as $stale) {
            foreach ($stale->getMatches() as $match) {
                unset($keptMatches[$match->getChallongeId()]);
            }

            $tally->discarded += 1 + $stale->getParticipants()->count() + $stale->getMatches()->count();

            $this->stages->remove($stale);
        }

        foreach ($keptMatches as $stale) {
            $stale->getStage()->removeMatch($stale);
            ++$tally->discarded;
        }

        $outcome = $tally->outcome();

        $this->report($tournament, $snapshot, $outcome);

        /*
         * The ledger line goes inside the flush, like every other admin
         * action: the archive must never be replayable for a write the
         * database rejected. A second line for a bracket already archived is
         * harmless rather than a doubling — which is the one property this
         * whole service is built around.
         */
        $this->flusher->flushThen(
            fn () => $this->ledgerService->logChallongeArchived($snapshot->slug),
        );

        return $outcome;
    }

    /**
     * Why this bracket cannot be written against this tournament, if it
     * cannot.
     */
    private function refuse(Tournament $tournament, ChallongeSnapshot $snapshot): ?ChallongeArchiveResult
    {
        if ($tournament->isTeamEvent()) {
            return ChallongeArchiveResult::TeamEvent;
        }

        $url = $tournament->getChallongeUrl();

        if (null === $url || '' === trim($url)) {
            return ChallongeArchiveResult::NoBracketRecorded;
        }

        try {
            $slug = ChallongeUrl::fromString($url)->slug;
        } catch (InvalidChallongeUrlException) {
            return ChallongeArchiveResult::NotThisBracket;
        }

        return $slug === $snapshot->slug ? null : ChallongeArchiveResult::NotThisBracket;
    }

    /**
     * The stage's entrants, keyed by the Challonge id its matches call them
     * by.
     *
     * The participant list and the standings table are two halves of one row
     * and the snapshot keeps them apart, because a standings row does not
     * reliably name the entrant it is about — a blader who linked their
     * Challonge account is rendered as that account instead. The join is
     * `ChallongeStandingsResolver`'s, through the match ids in the row's
     * history cell.
     *
     * @return array<int, TournamentParticipant>
     */
    private function archiveEntrants(
        TournamentStage $entity,
        ChallongeStage $stage,
        AliasIndex $index,
        ChallongeArchiveTally $tally,
    ): array {
        $placings = [];

        foreach ($this->standings->resolve($stage) as $placing) {
            if (null !== $placing->participant) {
                $placings[$placing->participant->id] = $placing;
            }
        }

        $entrants = [];

        foreach ($stage->participants as $participant) {
            $standing = ($placings[$participant->id] ?? null)?->standing;

            $entrant = $entity->participant($participant->id)
                ?? new TournamentParticipant($entity, $participant->id, $participant->name);

            $entrant->transcribe(
                name: $participant->name,
                challongeUser: $standing?->challongeUser,
                seed: $participant->seed,
                stageRank: $standing?->rank,
                advanced: null !== $standing && $this->advanced($standing),
                record: null === $standing ? ChallongeRecord::nothing() : $this->records->read($standing),
            );

            $entrant->isBlader($this->blader($index, $participant, $standing?->challongeUser, $tally));

            $entrants[$participant->id] = $entrant;
            ++$tally->participants;
        }

        foreach ($entity->getParticipants()->toArray() as $stale) {
            if (!isset($entrants[$stale->getChallongeId()])) {
                $entity->removeParticipant($stale);
                ++$tally->discarded;
            }
        }

        return $entrants;
    }

    /**
     * A stale match is not dropped here. A bracket that moved a match from one
     * stage to another would otherwise have it deleted by the stage it left
     * before the stage it joined could claim it; the leftovers are pruned once,
     * after every stage has had its turn.
     *
     * @param array<int, TournamentParticipant> $entrants
     * @param array<int, TournamentMatch>       $kept     the tournament's matches that have not been
     *                                                    claimed by a stage yet, by Challonge id
     */
    private function archiveMatches(
        TournamentStage $entity,
        ChallongeStage $stage,
        array $entrants,
        array &$kept,
        ChallongeArchiveTally $tally,
    ): void {
        foreach ($stage->matches as $match) {
            $this->archiveMatch($entity, $match, $entrants, $kept, $tally);
            unset($kept[$match->id]);
        }
    }

    /**
     * @param array<int, TournamentParticipant> $entrants
     * @param array<int, TournamentMatch>       $kept
     */
    private function archiveMatch(
        TournamentStage $entity,
        ChallongeMatch $match,
        array $entrants,
        array $kept,
        ChallongeArchiveTally $tally,
    ): TournamentMatch {
        $archived = $kept[$match->id] ?? new TournamentMatch($entity, $match->id);

        $archived->belongsTo($entity);
        $archived->transcribe($match->round, $match->identifier, $match->state, $match->forfeited, $match->consolation);
        $archived->between($this->entrant($entrants, $match->player1Id), $this->entrant($entrants, $match->player2Id));
        $archived->scored($match->score[0] ?? null, $match->score[1] ?? null);
        $archived->decided($this->entrant($entrants, $match->winnerId), $this->entrant($entrants, $match->loserId));

        /*
         * The rule that a single game is not written lives on the entity, so
         * that a path added later inherits it rather than having to remember
         * it. The count comes back off the collection for the same reason:
         * this is not the place that decides how many rows there are.
         */
        $archived->transcribeGames($match->games);

        $tally->games += $archived->getGames()->count();
        ++$tally->matches;

        return $archived;
    }

    /**
     * @param array<int, TournamentParticipant> $entrants
     */
    private function entrant(array $entrants, ?int $challongeId): ?TournamentParticipant
    {
        return null === $challongeId ? null : ($entrants[$challongeId] ?? null);
    }

    private function advanced(ChallongeStanding $standing): bool
    {
        foreach ($standing->labels as $label) {
            if (self::ADVANCED === mb_strtolower(trim($label))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Who the entrant is, or nobody.
     */
    private function blader(
        AliasIndex $index,
        ChallongeParticipant $participant,
        ?string $challongeUser,
        ChallongeArchiveTally $tally,
    ): ?Player {
        if (self::BYE === $this->normaliser->normalise($participant->name)) {
            return null;
        }

        $resolution = $this->aliases->resolveWith($index, $participant->name, $challongeUser);

        if ($resolution->isResolved()) {
            ++$tally->bladers;

            return $resolution->player;
        }

        $tally->nobodyIsCalled($participant->name);

        return null;
    }

    private function report(
        Tournament $tournament,
        ChallongeSnapshot $snapshot,
        ChallongeArchiveOutcome $outcome,
    ): void {
        $this->logger->info('Bracket archived', [
            'tournament' => $tournament->getTitle(),
            'bracket' => $snapshot->slug,
            'stages' => $outcome->stages,
            'participants' => $outcome->participants,
            'matches' => $outcome->matches,
            'games' => $outcome->games,
            'discarded' => $outcome->discarded,
        ]);

        if ([] === $outcome->unrecognised) {
            return;
        }

        $this->logger->warning('Bracket entrants nobody is called', [
            'tournament' => $tournament->getTitle(),
            'bracket' => $snapshot->slug,
            'names' => $outcome->unrecognised,
        ]);
    }
}
