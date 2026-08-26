<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\ClaimTeamOutcome;
use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentResult;
use App\Entity\TournamentTeam;
use App\Repository\TournamentRepository;
use App\Repository\TournamentResultRepository;
use App\Repository\TournamentTeamRepository;
use Psr\Log\LoggerInterface;

/**
 * Saying who was in a team, after the event was imported.
 *
 * An unclaimed team is the one place in this epic where a name nobody
 * recognises becomes a record rather than a question: `JG` finished tenth on
 * 11 July and `melhina` eleventh, both existed, and neither blocked the
 * import. This is the other half of that bargain — the operation that answers
 * the question later, writes the placements that were never written, and
 * awards the rank's points retroactively.
 *
 * Three rules, and they are the reason this is not just a setter:
 *
 * 1. **A claim never creates a blader.** An import may — a placement list is
 *    typed alongside the event and new people turn up at one — but a claim is
 *    filed weeks afterwards against a league that already knows everybody who
 *    was there. So the name goes through the resolver and it resolves or it
 *    refuses, exactly as `AliasService` does.
 * 2. **A claim never creates a team.** The entrant is what the bracket
 *    recorded; a spelling that reaches no entrant of that event is a typo, not
 *    a thirteenth team.
 * 3. **A blader finishes once per event.** Somebody already on the board
 *    played for another entrant, and a second placement would score them twice
 *    for one evening.
 *
 * A team already half known is claimable again — one member costs nothing to
 * allow and awards the known half their points, and the second name arriving
 * later is the same operation run twice.
 */
class TournamentTeamService
{
    public function __construct(
        private TournamentRepository $tournaments,
        private TournamentTeamRepository $teams,
        private TournamentResultRepository $results,
        private AliasResolver $resolver,
        private AliasNormaliser $normaliser,
        private F1Points $f1Points,
        private LedgerService $ledgerService,
        private FlusherInterface $flusher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string> $bladerNames
     */
    public function claim(
        string $tournamentTitle,
        string $teamName,
        array $bladerNames,
    ): ClaimTeamOutcome {
        $bladerNames = array_values(array_filter(
            array_map(trim(...), $bladerNames),
            static fn (string $name): bool => '' !== $name,
        ));

        if ([] === $bladerNames) {
            return ClaimTeamOutcome::refused(ClaimTeamResult::NoBladers);
        }

        $events = $this->tournaments->findByTitle($tournamentTitle);

        if (1 !== count($events)) {
            return ClaimTeamOutcome::refused([] === $events
                ? ClaimTeamResult::TournamentNotFound
                : ClaimTeamResult::TournamentIsAmbiguous);
        }

        $team = $this->teams->findByNormalised(
            $events[0],
            $this->normaliser->normalise($teamName),
        );

        if (null === $team) {
            return ClaimTeamOutcome::refused(ClaimTeamResult::TeamNotFound);
        }

        return $this->attach($events[0], $team, $bladerNames);
    }

    /**
     * Every entrant of one event, in finishing order.
     *
     * @return list<TournamentTeam>
     */
    public function forTournament(Tournament $tournament): array
    {
        return $this->teams->forTournament($tournament);
    }

    /**
     * Every team the league has on record, event by event, oldest first.
     *
     * @return list<TournamentTeam>
     */
    public function all(): array
    {
        $teams = [];

        foreach ($this->tournaments->everyEventInOrder() as $event) {
            foreach ($this->teams->forTournament($event) as $team) {
                $teams[] = $team;
            }
        }

        return $teams;
    }

    /**
     * @param list<string> $bladerNames
     */
    private function attach(
        Tournament $event,
        TournamentTeam $team,
        array $bladerNames,
    ): ClaimTeamOutcome {
        /*
         * One index for the whole claim: the two tables it reads do not change
         * while a claim runs, and a three-blader team would otherwise read
         * them three times.
         */
        $index = $this->resolver->index();

        /** @var list<Player> $joining */
        $joining = [];

        foreach ($bladerNames as $name) {
            $resolution = $this->resolver->resolveWith($index, $name);
            $blader = $resolution->player;

            if (null === $blader) {
                $this->logger->warning('Team claim rejected: the blader named is not one blader', [
                    'team' => $team->getName(),
                    'blader' => $name,
                    'why' => $resolution->match->value,
                ]);

                return ClaimTeamOutcome::refused(
                    $resolution->isAmbiguous()
                        ? ClaimTeamResult::BladerIsAmbiguous
                        : ClaimTeamResult::BladerNotFound,
                    $team,
                    $name,
                );
            }

            if ($team->hasMember($blader)) {
                continue;
            }

            if ($this->results->existsFor($event, $blader)) {
                return ClaimTeamOutcome::refused(
                    ClaimTeamResult::BladerAlreadyPlaced,
                    $team,
                    $blader->getName(),
                );
            }

            $joining[] = $blader;
        }

        if ([] === $joining) {
            return ClaimTeamOutcome::alreadyRecorded($team);
        }

        foreach ($joining as $blader) {
            $team->addMember($blader);
            $this->results->save($this->placementFor($event, $team, $blader));
        }

        $this->teams->save($team);

        $attached = array_map(
            static fn (Player $blader): string => $blader->getName(),
            $joining,
        );

        /*
         * The replay line goes inside the flush, like every other ledger
         * write: a claim that the database rejected must not leave a command
         * behind that would award the points again on a rebuild.
         */
        $this->flusher->flushThen(
            fn () => $this->ledgerService->logTeamClaimed(
                tournamentTitle: $event->getTitle(),
                teamName: $team->getName(),
                bladerNames: $attached,
            ),
        );

        return ClaimTeamOutcome::claimed($team, $attached);
    }

    private function placementFor(
        Tournament $event,
        TournamentTeam $team,
        Player $blader,
    ): TournamentResult {
        $result = new TournamentResult();
        $result->setTournament($event);
        $result->setPlayer($blader);
        $result->setRank($team->getRank());
        $result->setF1Points($this->f1Points->forRank($team->getRank()));
        $result->setBonusPoints(0);

        return $result;
    }
}
