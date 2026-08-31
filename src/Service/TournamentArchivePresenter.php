<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Player;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;

/**
 * Turns the additive Challonge archive into the read model used by one page.
 *
 * Nothing here decides league places or points. Those remain TournamentResult
 * data; this presenter only arranges the bracket transcription for display.
 *
 * @phpstan-type MatchStep array{match_id: int, consolation: bool, forfeited: bool, result: string, own_score: ?int, opponent_score: ?int, opponent: ?TournamentParticipant, label: string, short_label: string, column_label: string}
 * @phpstan-type CutPath array{participant: TournamentParticipant, steps: list<MatchStep>}
 * @phpstan-type MatchRow array{match: TournamentMatch, player1_form: list<string>, player2_form: list<string>}
 * @phpstan-type ByeRow array{participant: TournamentParticipant, form: list<string>}
 * @phpstan-type SwissRound array{number: int, matches: list<MatchRow>, byes: list<ByeRow>}
 * @phpstan-type SwissColumn array{key: string, label: string, title: ?string}
 * @phpstan-type SwissStage array{stage: TournamentStage, label: string, standings: list<array{participant: TournamentParticipant, form: list<string>}>, columns: list<SwissColumn>, rounds: list<SwissRound>}
 * @phpstan-type CutStage array{stage: TournamentStage, match_count: int, columns: list<string>, paths: list<CutPath>}
 * @phpstan-type FinishingPlace array{rank: int, name: string, player: ?Player, matches: int, wins: int, losses: int, draws: int, form: list<string>}
 */
final class TournamentArchivePresenter
{
    public function __construct(private readonly BracketRoundLabels $labels)
    {
    }

    /**
     * @param list<TournamentStage> $stages
     *
     * @return array{archived: bool, swiss: list<SwissStage>, cuts: list<CutStage>, finishing_order: list<FinishingPlace>}
     */
    public function present(array $stages): array
    {
        $swiss = [];
        $cuts = [];
        $matchCount = 0;
        $forms = [];
        foreach ($stages as $stage) {
            $matches = $stage->getMatches()->toArray();
            $matchCount += count($matches);

            if ([] === $matches) {
                continue;
            }

            usort($matches, self::matchesInBracketOrder(...));

            if ($this->labels->isCut($stage)) {
                $cuts[] = [
                    'stage' => $stage,
                    'match_count' => count($matches),
                    'columns' => $this->cutColumns($stage, $matches),
                    'paths' => $this->cutPaths($stage, $matches),
                ];

                continue;
            }

            $standings = $stage->getParticipants()->toArray();
            usort($standings, static fn (TournamentParticipant $left, TournamentParticipant $right): int => [$left->getStageRank() ?? PHP_INT_MAX, $left->getSeed() ?? PHP_INT_MAX]
                <=> [$right->getStageRank() ?? PHP_INT_MAX, $right->getSeed() ?? PHP_INT_MAX]
            );
            $timeline = $this->swissTimeline($stage, $matches);

            // The ranking stage's own forms, kept for the finishing order
            // below. It is stage zero, which is the league's existing rule.
            if ($stage === $stages[0]) {
                $forms = $timeline['forms'];
            }

            $swiss[] = [
                'stage' => $stage,
                'label' => $this->labels->stage($stage),
                'standings' => array_map(static fn (TournamentParticipant $participant): array => [
                    'participant' => $participant,
                    'form' => $timeline['forms'][$participant->getChallongeId()] ?? [],
                ], $standings),
                'columns' => $this->swissColumns($standings),
                'rounds' => $timeline['rounds'],
            ];
        }

        return [
            'archived' => $matchCount > 0,
            'swiss' => $swiss,
            'cuts' => $cuts,
            'finishing_order' => $this->finishingOrder($stages, $forms),
        ];
    }

    /**
     * Where the bracket says everybody finished.
     *
     * **The first stage's ranking, and nothing cleverer.** That is already the
     * league's rule — `ChallongeSnapshot::rankingStage()` returns stage zero
     * and `ChallongeStandingsResolver::finishingOrder()` reads exactly that —
     * so this is the same order the import writes, reconstructed from the two
     * rankings the archive persists rather than derived by a second rule that
     * could disagree with the first.
     *
     * It matters because an **unranked** event has no `TournamentResult` row
     * to read a finishing order off, by design: the results table is the
     * scoring record and an unranked event scores nothing. The order is still
     * a fact the bracket stated, and the archive kept it.
     *
     * Worth knowing that this is not the cut's order. On `Gamesplus 23-08` the
     * cut ranked Jape second and the event finished him third — Challonge ranks
     * a two-stage event on its Swiss table — and following the cut here would
     * quietly contradict every ranked page on the site.
     *
     * @param list<TournamentStage>    $stages
     * @param array<int, list<string>> $forms  the ranking stage's form strings, by entrant id
     *
     * @return list<FinishingPlace>
     */
    private function finishingOrder(array $stages, array $forms): array
    {
        $stage = $stages[0] ?? null;

        if (null === $stage) {
            return [];
        }

        $entrants = $stage->getParticipants()->toArray();
        usort($entrants, static fn (TournamentParticipant $left, TournamentParticipant $right): int => [$left->getStageRank() ?? PHP_INT_MAX, $left->getSeed() ?? PHP_INT_MAX]
            <=> [$right->getStageRank() ?? PHP_INT_MAX, $right->getSeed() ?? PHP_INT_MAX]
        );

        $order = [];
        $position = 0;

        foreach ($entrants as $entrant) {
            ++$position;
            $record = $entrant->getRecord();
            $wins = $record->wins ?? 0;
            $losses = $record->losses ?? 0;
            $draws = $record->ties ?? 0;

            $order[] = [
                // The rank Challonge printed where it printed one, so two
                // entrants who genuinely tied keep the same number rather than
                // being counted off the rows.
                'rank' => $entrant->getStageRank() ?? $position,
                'name' => $entrant->getPlayer()?->getName() ?? $entrant->getName(),
                'player' => $entrant->getPlayer(),
                'matches' => $wins + $losses + $draws,
                'wins' => $wins,
                'losses' => $losses,
                'draws' => $draws,
                'form' => $forms[$entrant->getChallongeId()] ?? [],
            ];
        }

        return $order;
    }

    /**
     * Challonge only renders the ranking methods configured for this bracket.
     * A non-null value is therefore the persisted trace that a column existed.
     *
     * @param list<TournamentParticipant> $participants
     *
     * @return list<SwissColumn>
     */
    private function swissColumns(array $participants): array
    {
        $definitions = [
            'score' => ['label' => 'Score', 'title' => null],
            'buchholz' => ['label' => 'MB', 'title' => 'Median-Buchholz'],
            'tieBreak' => ['label' => 'TB', 'title' => null],
            'points' => ['label' => 'Pts', 'title' => 'Total points scored'],
            'pointsDifferential' => ['label' => 'Diff', 'title' => 'Points differential'],
        ];
        $columns = [];

        foreach ($definitions as $key => $definition) {
            $getter = 'get'.ucfirst($key);
            foreach ($participants as $participant) {
                if (null !== $participant->{$getter}()) {
                    $columns[] = ['key' => $key, ...$definition];
                    break;
                }
            }
        }

        return $columns;
    }

    /**
     * @param list<TournamentMatch> $matches
     *
     * @return array{rounds: list<SwissRound>, forms: array<int, list<string>>}
     */
    private function swissTimeline(TournamentStage $stage, array $matches): array
    {
        $matchesByRound = [];
        foreach ($matches as $match) {
            $matchesByRound[$match->getRound()][] = $match;
        }

        $forms = [];
        $rounds = [];
        $byesAwarded = [];

        foreach ($matchesByRound as $number => $roundMatches) {
            $matchRows = [];
            $seen = [];

            foreach ($roundMatches as $match) {
                $player1 = $match->getPlayer1();
                $player2 = $match->getPlayer2();

                if (null !== $player1) {
                    $id = $player1->getChallongeId();
                    $seen[$id] = true;
                    $forms[$id][] = $match->outcomeFor($player1);
                }
                if (null !== $player2) {
                    $id = $player2->getChallongeId();
                    $seen[$id] = true;
                    $forms[$id][] = $match->outcomeFor($player2);
                }

                $matchRows[] = [
                    'match' => $match,
                    'player1_form' => null === $player1 ? [] : $forms[$player1->getChallongeId()],
                    'player2_form' => null === $player2 ? [] : $forms[$player2->getChallongeId()],
                ];
            }

            $byeRows = [];
            foreach ($stage->getParticipants() as $participant) {
                $id = $participant->getChallongeId();
                $byes = $participant->getByes() ?? 0;

                if (isset($seen[$id]) || ($byesAwarded[$id] ?? 0) >= $byes) {
                    continue;
                }

                $forms[$id][] = 'B';
                $byesAwarded[$id] = ($byesAwarded[$id] ?? 0) + 1;
                $byeRows[] = ['participant' => $participant, 'form' => $forms[$id]];
            }

            $rounds[] = ['number' => $number, 'matches' => $matchRows, 'byes' => $byeRows];
        }

        return ['rounds' => $rounds, 'forms' => $forms];
    }

    /**
     * @param list<TournamentMatch> $matches
     *
     * @return list<CutPath>
     */
    private function cutPaths(TournamentStage $stage, array $matches): array
    {
        $participants = $stage->getParticipants()->toArray();
        usort($participants, static fn (TournamentParticipant $left, TournamentParticipant $right): int => [$left->getStageRank() ?? PHP_INT_MAX, $left->getSeed() ?? PHP_INT_MAX]
            <=> [$right->getStageRank() ?? PHP_INT_MAX, $right->getSeed() ?? PHP_INT_MAX]
        );

        return array_map(function (TournamentParticipant $participant) use ($stage, $matches): array {
            $steps = [];

            foreach ($matches as $match) {
                $isPlayer1 = $match->getPlayer1() === $participant;
                if (!$isPlayer1 && $match->getPlayer2() !== $participant) {
                    continue;
                }

                $steps[] = [
                    'match_id' => $match->getChallongeId(),
                    'consolation' => $match->isConsolation(),
                    'forfeited' => $match->isForfeited(),
                    'result' => $match->outcomeFor($participant),
                    'own_score' => $isPlayer1 ? $match->getPlayer1Score() : $match->getPlayer2Score(),
                    'opponent_score' => $isPlayer1 ? $match->getPlayer2Score() : $match->getPlayer1Score(),
                    'opponent' => $isPlayer1 ? $match->getPlayer2() : $match->getPlayer1(),
                    'label' => $this->labels->long($stage, $match),
                    'short_label' => $this->labels->short($stage, $match),
                    'column_label' => $this->labels->column($stage, $match),
                ];
            }

            return ['participant' => $participant, 'steps' => $steps];
        }, $participants);
    }

    /**
     * @param list<TournamentMatch> $matches
     *
     * @return list<string>
     */
    private function cutColumns(TournamentStage $stage, array $matches): array
    {
        $columns = [];

        foreach ($matches as $match) {
            $columns[] = $this->labels->column($stage, $match);
        }

        return array_values(array_unique($columns));
    }

    private static function matchesInBracketOrder(TournamentMatch $left, TournamentMatch $right): int
    {
        return [$left->isConsolation(), $left->getRound(), $left->getChallongeId()]
            <=> [$right->isConsolation(), $right->getRound(), $right->getChallongeId()];
    }
}
