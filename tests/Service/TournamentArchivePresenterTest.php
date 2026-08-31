<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeRecord;
use App\Dto\ChallongeStageKind;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;
use App\Service\TournamentArchivePresenter;
use PHPUnit\Framework\TestCase;

final class TournamentArchivePresenterTest extends TestCase
{
    public function testSwissColumnsFollowTheRankingMethodsPresentInTheBracket(): void
    {
        $stage = new TournamentStage(new Tournament(), 0, ChallongeStageKind::Group);
        $stage->transcribe(ChallongeStageKind::Group, null, 'swiss', 1);
        $first = $this->participant(
            $stage,
            1,
            'Privv',
            1,
            new ChallongeRecord(wins: 1, losses: 0, ties: 0, score: 1.0, tieBreak: 1.0, points: 7, pointsDifferential: 4),
        );
        $second = $this->participant(
            $stage,
            2,
            'Bankai',
            2,
            new ChallongeRecord(wins: 0, losses: 1, ties: 0, score: 0.0, tieBreak: 0.0, points: 3, pointsDifferential: -4),
        );
        $this->match($stage, 1, 1, $first, $second, $first);

        $columns = (new TournamentArchivePresenter())->present([$stage])['swiss'][0]['columns'];

        self::assertSame(['score', 'tieBreak', 'points', 'pointsDifferential'], array_column($columns, 'key'));
        self::assertSame(['Score', 'TB', 'Pts', 'Diff'], array_column($columns, 'label'));
    }

    public function testAFourRoundCutUsesNumberedOpeningRoundsBeforeTheSemifinal(): void
    {
        $stage = new TournamentStage(new Tournament(), 0, ChallongeStageKind::Final);
        $stage->transcribe(ChallongeStageKind::Final, null, 'single elimination', 4);
        $rizzler = $this->participant($stage, 1, 'Rizzler', 3);
        $opponents = [
            $this->participant($stage, 2, 'Piyus', 9),
            $this->participant($stage, 3, 'Federico', 5),
            $this->participant($stage, 4, 'Giglio', 1),
            $this->participant($stage, 5, 'Sanya', 4),
        ];

        $this->match($stage, 1, 1, $rizzler, $opponents[0], $rizzler);
        $this->match($stage, 2, 2, $rizzler, $opponents[1], $rizzler);
        $this->match($stage, 3, 3, $rizzler, $opponents[2], $opponents[2]);
        $this->match($stage, 4, 4, $rizzler, $opponents[3], $rizzler, consolation: true);

        $cut = (new TournamentArchivePresenter())->present([$stage])['cuts'][0];
        $path = array_values(array_filter(
            $cut['paths'],
            static fn (array $candidate): bool => 'Rizzler' === $candidate['participant']->getName(),
        ))[0];

        self::assertSame(['R1', 'R2', 'SF', '3P'], array_column($path['steps'], 'short_label'));
        self::assertSame(['R1', 'R2', 'SF', 'F/3P'], $cut['columns']);
        self::assertSame(['R1', 'R2', 'SF', 'F/3P'], array_column($path['steps'], 'column_label'));
        self::assertSame(['Round 1', 'Round 2', 'Semi-finals', 'Third-place playoff'], array_column($path['steps'], 'label'));
    }

    private function participant(TournamentStage $stage, int $id, string $name, int $rank, ?ChallongeRecord $record = null): TournamentParticipant
    {
        $participant = new TournamentParticipant($stage, $id, $name);
        $participant->transcribe($name, null, $id, $rank, false, $record ?? ChallongeRecord::nothing());

        return $participant;
    }

    private function match(TournamentStage $stage, int $id, int $round, TournamentParticipant $player1, TournamentParticipant $player2, TournamentParticipant $winner, bool $consolation = false): void
    {
        $match = new TournamentMatch($stage, $id);
        $match->transcribe($round, null, 'complete', false, $consolation);
        $match->between($player1, $player2);
        $match->scored($winner === $player1 ? 7 : 4, $winner === $player2 ? 7 : 4);
        $match->decided($winner, $winner === $player1 ? $player2 : $player1);
    }
}
