<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentResult;

/**
 * Turns one blader's archive into the read model their profile is built from.
 *
 * The same shape as `TournamentArchivePresenter` and for the same reason: it
 * arranges what the brackets recorded and decides nothing about scoring. The
 * best-14 points table below it is still `TournamentResult` and still reads
 * exactly what it read before this class existed.
 *
 * **A career is not season-scoped.** The route names a season because the
 * points table is season-scoped, but 35 bladers have played in both, so
 * everything here counts every event and the page says so.
 *
 * Three counting rules were settled on #58 and are applied in this one place,
 * because #59's records board will read the same aggregation:
 *
 * - **A forfeit is a win and a loss, and no points either way.** Four matches
 *   in the corpus were awarded rather than played and carry no scoreline at
 *   all. Counting them at 0-0 would quietly drag every rate down.
 * - **A third-place playoff is a match like any other.** Sixteen of them. The
 *   corpus figure that excludes them was describing the corpus.
 * - **A draw is a third outcome, never half a loss.** There is exactly one,
 *   and reading it as a loss is the bug that reached review on #57.
 *
 * @phpstan-type CareerMatch array{label: string, outcome: string, own_score: ?int, opponent_score: ?int, forfeited: bool, opponent: ?Player, opponent_name: string, cut: bool}
 * @phpstan-type StageGroup array{label: string, matches: list<CareerMatch>}
 * @phpstan-type Rival array{opponent: ?Player, name: string, wins: int, losses: int, draws: int, met: int}
 * @phpstan-type CareerEvent array{tournament: Tournament, archived: bool, wins: int, losses: int, draws: int, rank: ?int, points: ?int, stage_rank: ?int, stage_label: ?string, won_the_cut: bool, stages: list<StageGroup>, cut: list<CareerMatch>}
 * @phpstan-type Career array{archived: bool, matches: int, wins: int, losses: int, draws: int, win_rate: ?float, points_for: int, points_against: int, strong_wins: int, best_streak: int, events: int, opponents: int, rivals: array{ahead: list<Rival>, even: list<Rival>, behind: list<Rival>}, timeline: list<CareerEvent>, unarchived_events: int}
 */
final class PlayerCareerPresenter
{
    public function __construct(private readonly BracketRoundLabels $labels)
    {
    }

    /**
     * @param list<TournamentMatch>  $matches archived matches, newest event first
     * @param list<TournamentResult> $results league placements, newest event first
     *
     * @return Career
     */
    public function present(Player $player, array $matches, array $results): array
    {
        $events = $this->timeline($player, $matches, $results);
        $rivals = $this->rivals($player, $matches);

        $wins = 0;
        $losses = 0;
        $draws = 0;
        $pointsFor = 0;
        $pointsAgainst = 0;
        $strongWins = 0;

        foreach ($matches as $match) {
            $side = $this->sideOf($player, $match);
            if (null === $side) {
                continue;
            }

            $outcome = $match->outcomeFor($side);
            match ($outcome) {
                'W' => ++$wins,
                'L' => ++$losses,
                default => ++$draws,
            };

            $own = $this->scoreFor($match, $side);
            $against = $this->scoreAgainst($match, $side);
            if (null === $own || null === $against) {
                continue;
            }

            $pointsFor += $own;
            $pointsAgainst += $against;

            if ('W' === $outcome && $own >= 8) {
                ++$strongWins;
            }
        }

        $played = $wins + $losses + $draws;
        $archived = [] !== $matches;

        return [
            'archived' => $archived,
            'matches' => $played,
            'wins' => $wins,
            'losses' => $losses,
            'draws' => $draws,
            'win_rate' => 0 === $played ? null : round($wins / $played * 100, 1),
            'points_for' => $pointsFor,
            'points_against' => $pointsAgainst,
            'strong_wins' => $strongWins,
            'best_streak' => $this->bestStreak($player, $matches),
            'events' => count(array_filter($events, static fn (array $event): bool => $event['archived'])),
            'opponents' => count($rivals['ahead']) + count($rivals['even']) + count($rivals['behind']),
            'rivals' => $rivals,
            'timeline' => $events,
            'unarchived_events' => count(array_filter($events, static fn (array $event): bool => !$event['archived'])),
        ];
    }

    /**
     * Every event the blader has a trace of, newest first.
     *
     * Both sources are walked, not just the archive. The two 2v2 evenings have
     * no stages, no entrants and no matches — a team match records only the
     * aggregate of its individual matchups, so there is no blader-level row to
     * write — and a timeline built from matches alone would leave a hole
     * exactly where the points table below it shows a score. They appear here
     * with no record, saying why.
     *
     * @param list<TournamentMatch>  $matches
     * @param list<TournamentResult> $results
     *
     * @return list<CareerEvent>
     */
    private function timeline(Player $player, array $matches, array $results): array
    {
        /** @var array<int, list<TournamentMatch>> $byEvent */
        $byEvent = [];
        /** @var array<int, Tournament> $events */
        $events = [];

        foreach ($matches as $match) {
            $tournament = $match->getTournament();
            $id = (int) $tournament->getId();
            $events[$id] = $tournament;
            $byEvent[$id][] = $match;
        }

        /** @var array<int, TournamentResult> $placements */
        $placements = [];
        foreach ($results as $result) {
            $tournament = $result->getTournament();
            $id = (int) $tournament->getId();
            $events[$id] ??= $tournament;
            $placements[$id] = $result;
        }

        $timeline = [];
        foreach ($events as $id => $tournament) {
            $timeline[] = $this->event(
                $player,
                $tournament,
                $byEvent[$id] ?? [],
                $placements[$id] ?? null,
            );
        }

        usort($timeline, static fn (array $left, array $right): int => [$right['tournament']->getHeldOn(), (int) $right['tournament']->getId()]
            <=> [$left['tournament']->getHeldOn(), (int) $left['tournament']->getId()]
        );

        return $timeline;
    }

    /**
     * @param list<TournamentMatch> $matches every archived match of this one event
     *
     * @return CareerEvent
     */
    private function event(Player $player, Tournament $tournament, array $matches, ?TournamentResult $result): array
    {
        $wins = 0;
        $losses = 0;
        $draws = 0;
        $stageRank = null;
        $stageLabel = null;
        $wonTheCut = false;
        $cut = [];
        /** @var array<string, StageGroup> $stages */
        $stages = [];

        foreach ($matches as $match) {
            $side = $this->sideOf($player, $match);
            if (null === $side) {
                continue;
            }

            $stage = $match->getStage();
            $isCut = $this->labels->isCut($stage);
            $outcome = $match->outcomeFor($side);
            match ($outcome) {
                'W' => ++$wins,
                'L' => ++$losses,
                default => ++$draws,
            };

            $row = [
                'label' => $this->labels->inStage($stage, $match),
                'outcome' => $outcome,
                'own_score' => $this->scoreFor($match, $side),
                'opponent_score' => $this->scoreAgainst($match, $side),
                'forfeited' => $match->isForfeited(),
                'opponent' => $this->opponentOf($match, $side)?->getPlayer(),
                'opponent_name' => $this->nameOf($this->opponentOf($match, $side)),
                'cut' => $isCut,
            ];

            $key = (string) $stage->getPosition();
            $stages[$key] ??= [
                'label' => $isCut ? 'Top cut' : $this->labels->stage($stage),
                'matches' => [],
            ];
            $stages[$key]['matches'][] = $row;

            if ($isCut) {
                $cut[] = $row;
                $wonTheCut = $wonTheCut || ('W' === $outcome && !$match->isConsolation() && $match->getRound() === $stage->getRounds());

                continue;
            }

            // The place the stage everybody played put them in, which is not
            // the event's finishing order: that is the cut's business.
            $stageRank ??= $side->getStageRank();
            $stageLabel ??= $this->labels->stage($stage);
        }

        return [
            'tournament' => $tournament,
            'archived' => [] !== $matches,
            'wins' => $wins,
            'losses' => $losses,
            'draws' => $draws,
            'rank' => $result?->getRank(),
            'points' => $result?->getTotalPoints(),
            'stage_rank' => $stageRank,
            'stage_label' => $stageLabel,
            'won_the_cut' => $wonTheCut,
            'stages' => array_values($stages),
            'cut' => $cut,
        ];
    }

    /**
     * Every opponent the blader has met, split by which way the record runs.
     *
     * A draw counts in the meetings and settles nothing, so a 1-1-1 record is
     * even. Rows are ordered by how often the two have met, because a 5-0
     * against somebody met five times says more than a 1-0 against somebody
     * met once, and the profile shows the first few of each group.
     *
     * @param list<TournamentMatch> $matches
     *
     * @return array{ahead: list<Rival>, even: list<Rival>, behind: list<Rival>}
     */
    private function rivals(Player $player, array $matches): array
    {
        /** @var array<string, Rival> $tally */
        $tally = [];

        foreach ($matches as $match) {
            $side = $this->sideOf($player, $match);
            if (null === $side) {
                continue;
            }

            $opponent = $this->opponentOf($match, $side);
            if (null === $opponent) {
                continue;
            }

            $blader = $opponent->getPlayer();
            $key = null === $blader ? 'name:'.$this->nameOf($opponent) : 'blader:'.$blader->getId();
            $tally[$key] ??= [
                'opponent' => $blader,
                'name' => null === $blader ? $this->nameOf($opponent) : $blader->getName(),
                'wins' => 0,
                'losses' => 0,
                'draws' => 0,
                'met' => 0,
            ];

            ++$tally[$key]['met'];
            match ($match->outcomeFor($side)) {
                'W' => ++$tally[$key]['wins'],
                'L' => ++$tally[$key]['losses'],
                default => ++$tally[$key]['draws'],
            };
        }

        $groups = ['ahead' => [], 'even' => [], 'behind' => []];
        foreach ($tally as $rival) {
            $group = match (true) {
                $rival['wins'] > $rival['losses'] => 'ahead',
                $rival['wins'] < $rival['losses'] => 'behind',
                default => 'even',
            };
            $groups[$group][] = $rival;
        }

        foreach ($groups as $name => $rivals) {
            usort($rivals, static fn (array $left, array $right): int => [$right['met'], $right['wins'] - $right['losses'], $left['name']]
                <=> [$left['met'], $left['wins'] - $left['losses'], $right['name']]
            );
            $groups[$name] = $rivals;
        }

        return $groups;
    }

    /**
     * The longest run of wins, oldest match to newest.
     *
     * A loss ends it and so does the draw, which is neither half a win nor
     * half a loss. The matches arrive newest first, so they are walked
     * backwards.
     *
     * @param list<TournamentMatch> $matches
     */
    private function bestStreak(Player $player, array $matches): int
    {
        $best = 0;
        $running = 0;

        foreach (array_reverse($matches) as $match) {
            $side = $this->sideOf($player, $match);
            if (null === $side) {
                continue;
            }

            if ('W' !== $match->outcomeFor($side)) {
                $running = 0;

                continue;
            }

            ++$running;
            $best = max($best, $running);
        }

        return $best;
    }

    /**
     * Which of the match's two entrants is this blader.
     *
     * Not cached across matches: the group stage and the cut number their
     * entrants in unrelated spaces, so a blader who made the cut is a
     * different `TournamentParticipant` in the second half of their own
     * evening.
     */
    private function sideOf(Player $player, TournamentMatch $match): ?TournamentParticipant
    {
        foreach ([$match->getPlayer1(), $match->getPlayer2()] as $side) {
            if (null !== $side && $side->getPlayer() === $player) {
                return $side;
            }
        }

        return null;
    }

    private function opponentOf(TournamentMatch $match, TournamentParticipant $side): ?TournamentParticipant
    {
        return $match->getPlayer1() === $side ? $match->getPlayer2() : $match->getPlayer1();
    }

    /**
     * An awarded match has no scoreline, so it reports none.
     *
     * The guard is on the flag rather than on the values, because a forfeit
     * that ever arrives carrying a 0-0 is still a match nobody bladed.
     */
    private function scoreFor(TournamentMatch $match, TournamentParticipant $side): ?int
    {
        if ($match->isForfeited()) {
            return null;
        }

        return $match->getPlayer1() === $side ? $match->getPlayer1Score() : $match->getPlayer2Score();
    }

    private function scoreAgainst(TournamentMatch $match, TournamentParticipant $side): ?int
    {
        if ($match->isForfeited()) {
            return null;
        }

        return $match->getPlayer1() === $side ? $match->getPlayer2Score() : $match->getPlayer1Score();
    }

    /**
     * The name to print for an entrant nobody has resolved to a blader.
     *
     * Every entrant in the replayed data does resolve, so this is reached only
     * by an archive written without going through the import screen. It still
     * has to say something rather than print an empty cell.
     */
    private function nameOf(?TournamentParticipant $participant): string
    {
        if (null === $participant) {
            return 'Bye';
        }

        return $participant->getPlayer()?->getName()
            ?? str_replace(' (invitation pending)', '', $participant->getName());
    }
}
