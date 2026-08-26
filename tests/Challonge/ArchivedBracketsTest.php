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
use App\Tests\Support\ServiceTestCase;
use Zenstruck\Foundry\Attribute\ResetDatabase;
use Zenstruck\Foundry\Attribute\WithStory;

/**
 * The archive, put to the eighteen brackets the league actually played.
 *
 * `ChallongeArchiveServiceTest` builds the shapes it wants; this one reads the
 * tracked snapshots in `var/data/challonge/` and writes all of them, which is
 * the only way to know what a backfill will actually produce. The numbers are
 * written out rather than derived, because a number that derived itself from
 * the same files would agree with anything — capturing a nineteenth bracket
 * will fail this test, and updating the counts is the point at which somebody
 * looks at what changed.
 */
#[ResetDatabase]
#[WithStory(SeasonStory::class)]
final class ArchivedBracketsTest extends ServiceTestCase
{
    /**
     * The two 2v2 events. Nothing in a snapshot says which they are —
     * `is_team` is false in all eighteen — so a team event is declared at
     * import, and holding teams is that declaration's persisted trace.
     */
    private const TEAM_EVENTS = ['uhxii7az', 'ivanixk6'];

    private const EVENTS_ARCHIVED = 16;

    private const STAGES = 30;

    private const PARTICIPANTS = 455;

    private const MATCHES = 951;

    /**
     * Zero, on purpose. Every one of the 947 played solo matches in the corpus
     * is a single game, and all fifty-one multi-game matches are team matches
     * — which are not archived. A backfill that produced 947 rows would be the
     * sign the rule had been bypassed.
     */
    private const GAMES = 0;

    private const FORFEITS = 4;

    /**
     * The eight rows of each cut's standings table, which is what `Advanced`
     * is a badge for on the stage before it.
     */
    private const ADVANCED = 108;

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

        self::assertCount(self::EVENTS_ARCHIVED, $archived);

        $stages = $this->everyStage();

        self::assertCount(self::STAGES, $stages);
        self::assertSame(self::PARTICIPANTS, $this->total($stages, static fn (TournamentStage $stage): int => $stage->getParticipants()->count()));
        self::assertSame(self::MATCHES, $this->total($stages, static fn (TournamentStage $stage): int => $stage->getMatches()->count()));
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
        foreach (self::TEAM_EVENTS as $slug) {
            self::assertSame(
                ChallongeArchiveResult::TeamEvent,
                $this->archiveOne($slug)->result,
                sprintf('"%s" is a team event.', $slug),
            );
        }

        foreach (self::TEAM_EVENTS as $slug) {
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
     * about them — 455 of 455, through the match ids in the row's history cell
     * where it has one and the name where it does not — and 108 of them carry
     * Challonge's `Advanced` badge, which is the eight who went through to
     * each of the fourteen cuts.
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

        self::assertSame(self::PARTICIPANTS, $ranked);
        self::assertSame(self::ADVANCED, $advanced);

        $forfeits = array_values(array_filter(
            $this->everyMatch(),
            static fn (TournamentMatch $match): bool => $match->isForfeited(),
        ));

        self::assertCount(self::FORFEITS, $forfeits);

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
            if (in_array($slug, self::TEAM_EVENTS, true)) {
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

        self::assertSame(self::MATCHES, $checked);
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

        if (in_array($slug, self::TEAM_EVENTS, true)) {
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
