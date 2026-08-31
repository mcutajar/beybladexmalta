<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeStageKind;
use App\Entity\Player;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\TournamentParticipant;
use App\Entity\TournamentStage;
use App\Service\ArchivedMatchReader;
use App\Service\LeagueRecordsPresenter;
use PHPUnit\Framework\TestCase;

/**
 * The records board's counting, on brackets built to have one shape each.
 *
 * No database and no fixtures: the presenter is handed a list of matches and
 * nothing else, so a test can say "these four matches, in this order" and
 * assert the record that falls out. `ArchivedRecordsBoardTest` does the
 * opposite and asserts against the brackets the league really played.
 */
final class LeagueRecordsPresenterTest extends TestCase
{
    /** @var array<string, Player> */
    private array $bladers = [];

    /** @var array<int, TournamentStage> */
    private array $stages = [];

    /** @var list<TournamentMatch> */
    private array $matches = [];

    private int $nextMatch = 1;

    /**
     * A win rate is only a record once somebody has played enough matches for
     * it to have settled down. Below that the board says nobody holds it,
     * rather than crowning whoever won their only match.
     */
    public function testAWinRateBelowTheMatchThresholdHoldsNoRecord(): void
    {
        $this->played(1, 'Mezz', 7, 'Belti', 2);

        self::assertNull($this->record('win-rate')['name']);
        self::assertNull($this->record('win-rate')['value']);

        // The match was played all the same, and the records that need no
        // threshold are held.
        self::assertSame('1', $this->record('matches')['value']);
    }

    public function testAWinRateAtTheThresholdIsARecord(): void
    {
        for ($round = 1; $round <= LeagueRecordsPresenter::MINIMUM_MATCHES; ++$round) {
            $this->played(1, 'Mezz', 7, 'Belti', 2);
        }

        self::assertSame('Mezz', $this->record('win-rate')['name']);
        self::assertSame('100%', $this->record('win-rate')['value']);
        self::assertSame('15–0 in 15 matches', $this->record('win-rate')['note']);
    }

    /**
     * The four awarded matches in the corpus carry no scoreline at all.
     * Counting them at 0-0 would drag every rate down and, on this page, would
     * also invent a shutout nobody bladed.
     */
    public function testAnAwardedMatchCountsInTheRecordAndScoresNothing(): void
    {
        $this->played(1, 'Mezz', 7, 'Belti', 3);
        $this->awarded(1, 'Mezz', 'Belti');

        self::assertSame('2', $this->record('matches')['value']);
        self::assertSame('7', $this->record('points')['value']);
        self::assertNull($this->record('shutouts')['value']);
    }

    /**
     * The one drawn match in the corpus is neither half a win nor half a loss,
     * and a run of wins that meets it stops there.
     */
    public function testADrawEndsAStreakWithoutBeingALoss(): void
    {
        $this->played(1, 'Mezz', 7, 'Belti', 3);
        $this->played(1, 'Mezz', 7, 'Belti', 1);
        $this->drawn(1, 'Mezz', 'Belti');
        $this->played(1, 'Mezz', 7, 'Belti', 0);

        self::assertSame('2 straight', $this->record('streak')['value']);
        self::assertSame('4', $this->record('matches')['value']);
    }

    /**
     * A streak is a run through time rather than through one evening, so it
     * carries across events — and the tile says how many it crossed.
     */
    public function testAStreakCarriesAcrossEvents(): void
    {
        $this->played(1, 'Mezz', 7, 'Belti', 3);
        $this->played(2, 'Mezz', 8, 'Belti', 1);
        $this->played(2, 'Belti', 7, 'Mezz', 5);

        self::assertSame('2 straight', $this->record('streak')['value']);
        self::assertSame('across 2 events', $this->record('streak')['note']);
    }

    /**
     * Only the winner's side of a shutout is read. There is no "most times
     * shut out", here or anywhere else on the page.
     */
    public function testAShutoutIsCreditedToTheWinnerAndNobodyElse(): void
    {
        $this->played(1, 'Mezz', 7, 'Belti', 0);

        self::assertSame('Mezz', $this->record('shutouts')['name']);
        self::assertSame('1', $this->record('shutouts')['value']);
    }

    /**
     * Two bladers level on a record is not a coin toss between two requests.
     */
    public function testARecordRanksItsTopThreeAndBreaksTiesByName(): void
    {
        $this->played(1, 'Belti', 9, 'Mezz', 2);
        $this->played(1, 'Amanda', 9, 'Mezz', 2);
        $this->played(1, 'Amanda', 9, 'Giglio', 2);
        $this->played(1, 'Giglio', 9, 'Mezz', 2);

        $record = $this->record('nines');

        self::assertSame('Amanda', $record['name']);
        self::assertSame('2', $record['value']);
        self::assertSame(
            [
                ['name' => 'Amanda', 'value' => '2'],
                ['name' => 'Belti', 'value' => '1'],
                ['name' => 'Giglio', 'value' => '1'],
            ],
            array_map(static fn (array $leader): array => [
                'name' => $leader['name'],
                'value' => $leader['value'],
            ], $record['leaders']),
        );
    }

    /**
     * A rivalry is stated from the side that is ahead, however the matches
     * happened to be seated in the bracket.
     */
    public function testARivalryIsStatedFromTheSideThatIsAhead(): void
    {
        $this->played(1, 'Mezz', 7, 'Belti', 3);
        $this->played(1, 'Mezz', 7, 'Belti', 1);
        $this->played(1, 'Belti', 7, 'Mezz', 4);

        self::assertSame('Mezz over Belti', $this->record('one-sided')['name']);
        self::assertSame('2–1', $this->record('one-sided')['leaders'][0]['value']);
    }

    /**
     * Two bladers who have met twice have not established anything. The tile
     * says so rather than calling a 2-0 the league's most one-sided rivalry.
     */
    public function testAPairMetTwiceIsNotYetARivalry(): void
    {
        $this->played(1, 'Mezz', 7, 'Belti', 3);
        $this->played(1, 'Mezz', 7, 'Belti', 1);

        self::assertNull($this->record('one-sided')['value']);
    }

    /**
     * An entrant nobody resolved to a blader would hold records under their
     * bracket spelling, and two spellings of one person would hold two. Their
     * matches still count towards the league's own totals.
     */
    public function testAnUnresolvedEntrantHoldsNoRecordButStillCounts(): void
    {
        $stage = $this->stage(1);
        $mezz = $this->entrant($stage, $this->blader('Mezz'));
        $stranger = new TournamentParticipant($stage, $this->nextMatch + 500, 'Somebody');

        $this->decide($stage, $mezz, 7, $stranger, 2);

        $board = $this->board();

        self::assertSame(1, $board['matches']);
        self::assertSame(1, $board['bladers']);
        self::assertSame('Mezz', $this->record('matches')['name']);
    }

    public function testAnEmptyArchiveIsNotABrokenBoard(): void
    {
        $board = $this->board();

        self::assertFalse($board['archived']);
        self::assertSame(0, $board['matches']);
        self::assertSame(
            array_fill(0, 9, null),
            array_column($board['records'], 'value'),
        );
    }

    /**
     * @return array{key: string, label: string, name: ?string, player: ?Player, value: ?string, note: ?string, tone: string, leaders: list<array{name: string, player: ?Player, value: string}>}
     */
    private function record(string $key): array
    {
        foreach ($this->board()['records'] as $record) {
            if ($record['key'] === $key) {
                return $record;
            }
        }

        self::fail(sprintf('The board has no "%s" record.', $key));
    }

    /**
     * @return array{archived: bool, matches: int, events: int, bladers: int, points: int, minimum_matches: int, records: list<array{key: string, label: string, name: ?string, player: ?Player, value: ?string, note: ?string, tone: string, leaders: list<array{name: string, player: ?Player, value: string}>}>}
     */
    private function board(): array
    {
        return (new LeagueRecordsPresenter(new ArchivedMatchReader()))->present($this->matches);
    }

    private function played(int $event, string $winner, int $winnerScore, string $loser, int $loserScore): void
    {
        $stage = $this->stage($event);

        $this->decide(
            $stage,
            $this->entrant($stage, $this->blader($winner)),
            $winnerScore,
            $this->entrant($stage, $this->blader($loser)),
            $loserScore,
        );
    }

    /**
     * A match nobody won: 0-0 with neither entrant recorded as winner or
     * loser, which is exactly how the corpus's one drawn match is archived.
     */
    private function drawn(int $event, string $one, string $two): void
    {
        $stage = $this->stage($event);
        $match = $this->match($stage);

        $match->between($this->entrant($stage, $this->blader($one)), $this->entrant($stage, $this->blader($two)));
        $match->scored(0, 0);
    }

    private function awarded(int $event, string $winner, string $loser): void
    {
        $stage = $this->stage($event);
        $match = $this->match($stage, forfeited: true);

        $first = $this->entrant($stage, $this->blader($winner));
        $second = $this->entrant($stage, $this->blader($loser));

        $match->between($first, $second);
        $match->decided($first, $second);
    }

    private function decide(TournamentStage $stage, TournamentParticipant $winner, int $winnerScore, TournamentParticipant $loser, int $loserScore): void
    {
        $match = $this->match($stage);

        $match->between($winner, $loser);
        $match->scored($winnerScore, $loserScore);
        $match->decided($winner, $loser);
    }

    private function match(TournamentStage $stage, bool $forfeited = false): TournamentMatch
    {
        $match = new TournamentMatch($stage, $this->nextMatch++);
        $match->transcribe(1, null, 'complete', $forfeited, false);

        $this->matches[] = $match;

        return $match;
    }

    /**
     * A fresh entrant row per match, which is what the archive holds anyway: a
     * blader who makes the cut is two `TournamentParticipant` rows for one
     * evening, and the board has to read through to the blader either way.
     */
    private function entrant(TournamentStage $stage, Player $player): TournamentParticipant
    {
        $entrant = new TournamentParticipant($stage, $this->nextMatch * 1000, $player->getName());
        $entrant->isBlader($player);

        return $entrant;
    }

    private function stage(int $event): TournamentStage
    {
        if (!isset($this->stages[$event])) {
            $tournament = new Tournament();
            $tournament->setId($event);

            $this->stages[$event] = new TournamentStage($tournament, 0, ChallongeStageKind::Single);
        }

        return $this->stages[$event];
    }

    private function blader(string $name): Player
    {
        if (!isset($this->bladers[$name])) {
            $player = new Player();
            $player->setId(count($this->bladers) + 1);
            $player->setName($name);

            $this->bladers[$name] = $player;
        }

        return $this->bladers[$name];
    }
}
