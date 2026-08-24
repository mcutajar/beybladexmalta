<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Dto\ChallongeMatch;
use App\Dto\ChallongeParticipant;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Dto\ChallongeStageKind;
use App\Dto\ChallongeUrl;
use App\Exception\ChallongeFetchException;
use App\Service\ChallongeModuleParser;
use App\Service\ChallongeStandingsParser;
use App\Service\ChallongeStoreNormaliser;
use App\Tests\Support\FakeChallonge;
use PHPUnit\Framework\TestCase;

final class ChallongeStoreNormaliserTest extends TestCase
{
    private ChallongeStoreNormaliser $normaliser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normaliser = new ChallongeStoreNormaliser(new ChallongeStandingsParser());
    }

    /**
     * Challonge puts the Swiss rounds in `groups[0]` and the cut at the top
     * level. Here they are one list, in the order they were played.
     */
    public function testItFlattensATwoStageBracketIntoGroupThenFinal(): void
    {
        $snapshot = $this->normaliseFixture();

        self::assertSame(
            [ChallongeStageKind::Group, ChallongeStageKind::Final],
            array_map(static fn (ChallongeStage $stage): ChallongeStageKind => $stage->kind, $snapshot->stages),
        );

        self::assertSame('swiss', $snapshot->stages[0]->format);
        self::assertSame('Group A', $snapshot->stages[0]->name);
        self::assertSame('single elimination', $snapshot->stages[1]->format);
    }

    /**
     * A Swiss-only or round-robin bracket has no groups: the top level is the
     * whole tournament, so calling it a final stage would be a lie.
     */
    public function testABracketWithOneStageIsNeitherAGroupNorAFinal(): void
    {
        $snapshot = $this->normalise([
            'tournament' => ['id' => 7, 'tournament_type' => 'round robin', 'state' => 'complete'],
            'matches_by_round' => ['1' => [$this->match(1)]],
            'groups' => [],
        ]);

        self::assertCount(1, $snapshot->stages);
        self::assertSame(ChallongeStageKind::Single, $snapshot->stages[0]->kind);
        self::assertSame('round robin', $snapshot->stages[0]->format);
    }

    public function testItKeepsTheRoundTitles(): void
    {
        $rounds = $this->normaliseFixture()->stages[1]->rounds;

        self::assertSame([1, 2], array_map(static fn ($round): int => $round->number, $rounds));
        self::assertSame(['Semifinals', 'Finals'], array_map(static fn ($round): ?string => $round->title, $rounds));
    }

    /**
     * The third-place playoff hangs off the store on its own rather than
     * sitting in `matches_by_round`, so a normaliser that only walked the
     * rounds would lose one match in every bracket that has a cut.
     */
    public function testItPicksUpTheThirdPlacePlayoff(): void
    {
        $final = $this->normaliseFixture()->stages[1];

        $playoff = $this->matchWithId($final, 903);

        self::assertTrue($playoff->consolation);
        self::assertSame('3P', $playoff->identifier);
        self::assertSame(0, $playoff->round);

        self::assertSame(
            [false, false, false, true],
            array_map(static fn (ChallongeMatch $match): bool => $match->consolation, $final->matches),
            'The playoff belongs after the final, and flagged, so it is never mistaken for it.',
        );
    }

    public function testItDoesNotCountTheSamePlayoffTwice(): void
    {
        $final = $this->normaliseFixture()->stages[1];

        $ids = array_map(static fn (ChallongeMatch $match): int => $match->id, $final->matches);

        self::assertSame($ids, array_unique($ids));
    }

    public function testItKeepsEveryGameOfAMultiGameMatch(): void
    {
        $match = $this->matchWithId($this->normaliseFixture()->stages[1], 904);

        self::assertSame([[7, 5], [4, 7], [7, 6]], $match->games);
        self::assertSame([2, 1], $match->score, 'A multi-game match scores games won, not points.');
        self::assertSame(21, $match->winnerId);
        self::assertSame(23, $match->loserId);
    }

    public function testItKeepsAForfeitAsAForfeit(): void
    {
        $match = $this->matchWithId($this->normaliseFixture()->stages[0], 702);

        self::assertTrue($match->forfeited);
        self::assertSame([], $match->games);
        self::assertSame([], $match->score);
    }

    /**
     * The two stages of the same bracket use disjoint id spaces — the same
     * blader is a different id in each, with nothing but their name in common.
     * That is why participants are listed per stage and never merged here.
     */
    public function testEachStageCarriesItsOwnEntrantsInSeededOrder(): void
    {
        $snapshot = $this->normaliseFixture();

        $group = array_map(static fn (ChallongeParticipant $p): array => [$p->seed, $p->name], $snapshot->stages[0]->participants);
        $final = array_map(static fn (ChallongeParticipant $p): int => $p->id, $snapshot->stages[1]->participants);

        self::assertSame([[1, 'Obelix'], [2, 'Sanya0207'], [3, 'Guy "The {Bracket}" \o/'], [4, 'giglio15 (invitation pending)']], $group);

        self::assertSame(
            [],
            array_intersect(array_map(static fn (ChallongeParticipant $p): int => $p->id, $snapshot->stages[0]->participants), $final),
        );
    }

    public function testItReadsTheStandingsOfEveryStage(): void
    {
        $snapshot = $this->normaliseFixture();

        self::assertCount(4, $snapshot->stages[0]->standings);
        self::assertCount(4, $snapshot->stages[1]->standings);
        self::assertTrue($snapshot->hasStandings());

        self::assertSame(
            ['Match W-L-T (wins +1.0, ties +0.5)', 'Byes (+1.0)', 'Score', 'Buchholz', 'TB', 'Pts Diff'],
            array_keys($snapshot->stages[0]->standings[0]->columns),
        );

        self::assertSame([], $snapshot->stages[1]->standings[0]->columns, 'A final-stage table is rank, name and account, and nothing else.');
    }

    public function testItKeepsWhereTheBracketCameFrom(): void
    {
        $snapshot = $this->normaliseFixture();

        self::assertSame('fixture1', $snapshot->slug);
        self::assertSame('https://challonge.com/fixture1/module?show_standings=1', $snapshot->sourceUrl);
        self::assertSame(18169778, $snapshot->tournamentId);
        self::assertSame('single elimination', $snapshot->tournamentType);
        self::assertSame('complete', $snapshot->tournamentState);
        self::assertFalse($snapshot->isTeamTournament);
        self::assertSame(7, $snapshot->matchCount(), 'Three Swiss matches, three in the cut, and the playoff.');
    }

    public function testItRefusesAStoreWithNoTournamentInIt(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('carries no tournament id or type');

        $this->normalise(['tournament' => ['state' => 'complete']]);
    }

    /**
     * The store is an undocumented embed payload. A field that changes type is
     * how we would find out it had changed shape, so it has to be loud — a
     * snapshot quietly missing a column is the one outcome worth avoiding.
     */
    public function testItRefusesAFieldThatHasChangedType(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('The Challonge field "raw_identifier" holds int where text was expected. The module payload has changed shape.');

        $this->normalise([
            'tournament' => ['id' => 7, 'tournament_type' => 'swiss', 'state' => 'complete'],
            'matches_by_round' => ['1' => [['id' => 1, 'round' => 1, 'raw_identifier' => 3]]],
        ]);
    }

    /**
     * Null, on the other hand, is Challonge's ordinary way of saying a bracket
     * had no playoff, or a match no winner yet, or an entrant no account.
     */
    public function testItTakesEveryNullChallongeLegitimatelyWrites(): void
    {
        $snapshot = $this->normalise([
            'tournament' => ['id' => 7, 'tournament_type' => 'swiss', 'state' => 'complete', 'is_team' => null],
            'name' => null,
            'third_place_match' => null,
            'matches_by_round' => ['1' => [[
                'id' => 1,
                'round' => 1,
                'state' => 'open',
                'raw_identifier' => 'A',
                'player1' => ['id' => 11, 'seed' => 1, 'display_name' => 'Obelix', 'participant_id' => null],
                'player2' => null,
                'games' => [],
                'scores' => [],
                'winner_id' => null,
                'loser_id' => null,
                'forfeited' => null,
            ]]],
        ]);

        $match = $snapshot->stages[0]->matches[0];

        self::assertNull($match->player2Id);
        self::assertNull($match->winnerId);
        self::assertFalse($match->forfeited);
        self::assertSame([], $match->games);
        self::assertNull($snapshot->stages[0]->name);
        self::assertNull($snapshot->stages[0]->participants[0]->participantId);
    }

    public function testItRefusesAMatchWithNoId(): void
    {
        $this->expectException(ChallongeFetchException::class);
        $this->expectExceptionMessage('carries no id');

        $this->normalise([
            'tournament' => ['id' => 7, 'tournament_type' => 'swiss', 'state' => 'complete'],
            'matches_by_round' => ['1' => [['round' => 1]]],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function match(int $id): array
    {
        return [
            'id' => $id,
            'round' => 1,
            'raw_identifier' => 'A',
            'state' => 'complete',
            'player1' => ['id' => 11, 'seed' => 1, 'display_name' => 'Obelix'],
            'player2' => ['id' => 12, 'seed' => 2, 'display_name' => 'Fairy'],
            'games' => [[7, 4]],
            'scores' => [7, 4],
            'winner_id' => 11,
            'loser_id' => 12,
        ];
    }

    private function matchWithId(ChallongeStage $stage, int $id): ChallongeMatch
    {
        foreach ($stage->matches as $match) {
            if ($id === $match->id) {
                return $match;
            }
        }

        self::fail(sprintf('The stage holds no match %d.', $id));
    }

    private function normaliseFixture(): ChallongeSnapshot
    {
        $page = FakeChallonge::modulePage();
        $moduleParser = new ChallongeModuleParser();

        return $this->normaliser->normalise(
            store: $moduleParser->readStore($page),
            bodyScorecardHtml: $moduleParser->readScorecard($page),
            url: ChallongeUrl::fromString('https://challonge.com/fixture1'),
            fetchedAt: new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
        );
    }

    /**
     * @param array<string, mixed> $store
     */
    private function normalise(array $store): ChallongeSnapshot
    {
        return $this->normaliser->normalise(
            store: $store,
            bodyScorecardHtml: null,
            url: ChallongeUrl::fromString('https://challonge.com/fixture1'),
            fetchedAt: new \DateTimeImmutable('2026-08-24T12:00:00+00:00'),
        );
    }
}
