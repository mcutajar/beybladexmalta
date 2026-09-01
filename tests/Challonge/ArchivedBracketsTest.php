<?php

declare(strict_types=1);

namespace App\Tests\Challonge;

use App\Dto\ChallongeArchiveOutcome;
use App\Entity\Tournament;
use App\Entity\TournamentMatch;
use App\Entity\TournamentStage;
use App\Repository\PlayerRepository;
use App\Repository\TournamentStageRepository;
use App\Service\ChallongeArchiveResult;
use App\Service\ChallongeArchiveService;
use App\Service\ChallongeSnapshotFiles;
use App\Service\ChallongeSnapshotReader;
use App\Tests\Factory\TournamentFactory;
use App\Tests\Factory\TournamentTeamFactory;
use App\Tests\Story\SeasonStory;
use App\Tests\Support\ReadsTheLeaguesCorpus;
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

/**
 * The archive, put to every bracket the league actually played.
 *
 * `ChallongeArchiveServiceTest` builds the shapes it wants; this one reads the
 * tracked snapshots in `var/data/challonge/` and writes all of them, which is
 * the only way to know what a backfill will actually produce.
 *
 * The totals it used to assert — 34 stages, 525 entrants, 1091 matches — are
 * gone, and what stands in their place is the comparison they were an
 * indirect way of making: the rows the archive wrote against the snapshot it
 * wrote them from. That is a stronger check than a number, because a number
 * agrees with a backfill that dropped one bracket and gained another, and it
 * costs nothing on the two evenings a week the league imports results. See
 * `ReadsTheLeaguesCorpus` for the reasoning in full.
 */
#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ArchivedBracketsTest extends ServiceTestCase
{
    use ReadsTheLeaguesCorpus;

    /**
     * Zero, on purpose, and a genuine constant rather than a census: every
     * played solo match in the corpus is a single game, and every multi-game
     * match is a team match, which is not archived. A backfill that produced
     * a games row at all would be the sign the rule had been bypassed.
     */
    private const GAMES = 0;

    private ChallongeArchiveService $archive;

    /**
     * One event per bracket, kept for the length of a test.
     *
     * Eighteen archives and then three walks of the result is enough work
     * without looking each event up again every time.
     *
     * @var array<string, Tournament>
     */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->archive = $this->service(ChallongeArchiveService::class);
    }

    /**
     * The counts a backfill produces, and the one it must not: nothing about
     * archiving creates a blader. The sixty-six spellings the corpus holds
     * that reach nobody are reported so somebody can file the aliases, and the
     * players table is left exactly as it was.
     */
    public function testItArchivesEveryCapturedBracketAndInventsNobody(): void
    {
        $bladers = $this->service(PlayerRepository::class)->findAll();

        $archived = $this->archiveEverything();

        self::assertSame($this->soloSlugs(), $archived, 'Every bracket that is not a 2v2 event should archive.');

        $stages = $this->everyStage();
        $expected = $this->snapshotTotals($archived);

        self::assertCount($expected['stages'], $stages);
        self::assertSame($expected['participants'], $this->total($stages, static fn (TournamentStage $stage): int => $stage->getParticipants()->count()));
        self::assertSame($expected['matches'], $this->total($stages, static fn (TournamentStage $stage): int => $stage->getMatches()->count()));
        self::assertCount(count($bladers), $this->service(PlayerRepository::class)->findAll());
    }

    /**
     * The done-when this whole table is shaped around: nothing doubles.
     */
    public function testArchivingEveryBracketTwiceLeavesTheSameRows(): void
    {
        $this->archiveEverything();
        $first = $this->rowCounts();

        $this->archiveEverything();

        self::assertSame($first, $this->rowCounts());
    }

    public function testTheGamesTableIsEmptyAfterTheBackfill(): void
    {
        $this->archiveEverything();

        self::assertSame(self::GAMES, $this->total(
            $this->everyMatch(),
            static fn (TournamentMatch $match): int => $match->getGames()->count(),
        ));
    }

    /**
     * A team event archives its entrants and nothing else. There is no honest
     * blader-level row to write out of a team match, and its entrants are
     * teams, so a `TournamentParticipant` for one would be a category error.
     */
    public function testATeamEventArchivesNothing(): void
    {
        foreach ($this->teamEventSlugs() as $slug) {
            self::assertSame(
                ChallongeArchiveResult::TeamEvent,
                $this->archiveOne($slug)->result,
                sprintf('"%s" is a team event.', $slug),
            );
        }

        foreach ($this->teamEventSlugs() as $slug) {
            self::assertSame(
                [],
                $this->stages($this->eventFor($slug)),
                sprintf('"%s" is a team event and should have no stages.', $slug),
            );
        }
    }

    /**
     * What the standings tables and the forfeits came to.
     *
     * Every entrant of every archived bracket was joined to the row that is
     * about them — 525 of 525, through the match ids in the row's history cell
     * where it has one and the name where it does not — and 128 of them carry
     * Challonge's `Advanced` badge, which is the eight who went through to
     * each of the sixteen cuts.
     *
     * The four forfeits are one of the three ways Challonge says nobody
     * played: all four are complete, all four have a winner, and none of them
     * has a scoreline.
     */
    public function testItKeepsWhatTheStandingsAndTheForfeitsSaid(): void
    {
        $this->archiveEverything();

        $ranked = 0;
        $advanced = 0;

        foreach ($this->everyStage() as $stage) {
            foreach ($stage->getParticipants() as $entrant) {
                $ranked += null === $entrant->getStageRank() ? 0 : 1;
                $advanced += $entrant->hasAdvanced() ? 1 : 0;
            }
        }

        $expected = $this->snapshotTotals($this->soloSlugs());

        self::assertSame($expected['participants'], $ranked, 'Every archived entrant should carry the rank its standings row gave it.');
        self::assertSame($expected['advanced'], $advanced, 'The entrants badged Advanced should be exactly the ones the cut stage holds.');

        $forfeits = array_values(array_filter(
            $this->everyMatch(),
            static fn (TournamentMatch $match): bool => $match->isForfeited(),
        ));

        self::assertCount($expected['forfeits'], $forfeits);

        foreach ($forfeits as $forfeit) {
            self::assertFalse($forfeit->wasPlayed());
            self::assertNull($forfeit->getPlayer1Score());
            self::assertNotNull($forfeit->getWinner());
        }
    }

    /**
     * Every match a snapshot holds round-trips: both entrants, the scoreline
     * and the winner come back as the bracket stated them.
     */
    public function testEveryMatchRoundTrips(): void
    {
        $this->archiveEverything();

        $checked = 0;

        foreach ($this->slugs() as $slug) {
            if (in_array($slug, $this->teamEventSlugs(), true)) {
                continue;
            }

            $archived = [];

            foreach ($this->stages($this->eventFor($slug)) as $stage) {
                foreach ($stage->getMatches() as $match) {
                    $archived[$match->getChallongeId()] = $match;
                }
            }

            foreach ($this->service(ChallongeSnapshotReader::class)->read($slug)->stages as $stage) {
                foreach ($stage->matches as $match) {
                    $entrants = [];

                    foreach ($stage->participants as $participant) {
                        $entrants[$participant->id] = $participant->name;
                    }

                    self::assertArrayHasKey($match->id, $archived, sprintf('Match %d of %s was not archived.', $match->id, $slug));

                    $stored = $archived[$match->id];

                    self::assertSame(
                        [
                            $this->entrant($entrants, $match->player1Id),
                            $this->entrant($entrants, $match->player2Id),
                            $match->score[0] ?? null,
                            $match->score[1] ?? null,
                            $this->entrant($entrants, $match->winnerId),
                            $match->round,
                            $match->identifier,
                        ],
                        [
                            $stored->getPlayer1()?->getName(),
                            $stored->getPlayer2()?->getName(),
                            $stored->getPlayer1Score(),
                            $stored->getPlayer2Score(),
                            $stored->getWinner()?->getName(),
                            $stored->getRound(),
                            $stored->getIdentifier(),
                        ],
                        sprintf('Match %d of %s did not round-trip.', $match->id, $slug),
                    );

                    ++$checked;
                }
            }
        }

        self::assertSame($this->snapshotTotals($this->soloSlugs())['matches'], $checked);
    }

    /**
     * What the snapshots say the archive should have produced.
     *
     * The other side of every count in this file. Reading it off the same
     * snapshots the archive was handed is the point: the two sides are the
     * bracket as transcribed and the bracket as stored, and the archive is
     * the code between them.
     *
     * @param list<string> $slugs
     *
     * @return array{stages: int, participants: int, matches: int, forfeits: int, advanced: int}
     */
    private function snapshotTotals(array $slugs): array
    {
        $totals = ['stages' => 0, 'participants' => 0, 'matches' => 0, 'forfeits' => 0, 'advanced' => 0];
        $reader = $this->service(ChallongeSnapshotReader::class);

        foreach ($slugs as $slug) {
            $snapshot = $reader->read($slug);

            $totals['stages'] += count($snapshot->stages);
            $totals['participants'] += $snapshot->participantCount();
            $totals['matches'] += $snapshot->matchCount();
            $totals['forfeits'] += $snapshot->forfeitedMatchCount();

            /*
             * `Advanced` is a badge on the stage before a cut, so the
             * entrants carrying it are exactly the entrants the cut holds.
             * A bracket with no cut badges nobody.
             */
            $cut = $snapshot->cutStage();
            $totals['advanced'] += null === $cut ? 0 : count($cut->participants);
        }

        return $totals;
    }

    /**
     * Every captured bracket that is not a 2v2 event, which is what a backfill
     * writes rows for.
     *
     * @return list<string>
     */
    private function soloSlugs(): array
    {
        return array_values(array_filter(
            $this->slugs(),
            fn (string $slug): bool => !in_array($slug, $this->teamEventSlugs(), true),
        ));
    }

    /**
     * @param array<int, string> $entrants
     */
    private function entrant(array $entrants, ?int $challongeId): ?string
    {
        return null === $challongeId ? null : ($entrants[$challongeId] ?? null);
    }

    /**
     * One event per captured bracket, which is what the archive attaches to.
     *
     * @return list<string> the brackets that were archived
     */
    private function archiveEverything(): array
    {
        $archived = [];

        foreach ($this->slugs() as $slug) {
            if (ChallongeArchiveResult::Archived === $this->archiveOne($slug)->result) {
                $archived[] = $slug;
            }
        }

        return $archived;
    }

    private function archiveOne(string $slug): ChallongeArchiveOutcome
    {
        return $this->archive->archive(
            $this->eventFor($slug),
            $this->service(ChallongeSnapshotReader::class)->read($slug),
        );
    }

    /**
     * @return list<string>
     */
    private function slugs(): array
    {
        $slugs = array_map(
            static fn (string $path): string => basename($path, '.json'),
            (array) glob($this->service(ChallongeSnapshotFiles::class)->directory().'/*.json'),
        );

        sort($slugs);

        return $slugs;
    }

    private function eventFor(string $slug): Tournament
    {
        if (isset($this->events[$slug])) {
            return $this->events[$slug];
        }

        $event = $this->events[$slug] = TournamentFactory::createOne([
            'title' => 'Event from '.$slug,
            'season' => SeasonStory::freeSeason(),
            'challongeUrl' => 'https://challonge.com/'.$slug,
        ]);

        if (in_array($slug, $this->teamEventSlugs(), true)) {
            TournamentTeamFactory::createOne(['tournament' => $event, 'name' => 'a team', 'rank' => 1]);
        }

        return $event;
    }

    /**
     * @return list<TournamentStage>
     */
    private function stages(Tournament $event): array
    {
        return $this->service(TournamentStageRepository::class)->forTournament($event);
    }

    /**
     * @return list<TournamentStage>
     */
    private function everyStage(): array
    {
        $stages = [];

        foreach ($this->slugs() as $slug) {
            foreach ($this->stages($this->eventFor($slug)) as $stage) {
                $stages[] = $stage;
            }
        }

        return $stages;
    }

    /**
     * @param ?list<TournamentStage> $stages
     *
     * @return list<TournamentMatch>
     */
    private function everyMatch(?array $stages = null): array
    {
        $matches = [];

        foreach ($stages ?? $this->everyStage() as $stage) {
            foreach ($stage->getMatches() as $match) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /**
     * @return array{stages: int, participants: int, matches: int, games: int}
     */
    private function rowCounts(): array
    {
        $stages = $this->everyStage();

        return [
            'stages' => count($stages),
            'participants' => $this->total($stages, static fn (TournamentStage $stage): int => $stage->getParticipants()->count()),
            'matches' => $this->total($stages, static fn (TournamentStage $stage): int => $stage->getMatches()->count()),
            'games' => $this->total($this->everyMatch($stages), static fn (TournamentMatch $match): int => $match->getGames()->count()),
        ];
    }

    /**
     * @template T
     *
     * @param list<T>          $rows
     * @param callable(T): int $how
     */
    private function total(array $rows, callable $how): int
    {
        return array_sum(array_map($how, $rows));
    }
}
