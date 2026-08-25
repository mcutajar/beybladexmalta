<?php

declare(strict_types=1);

namespace App\Tests\Challonge;

use App\Dto\ChallongeJoin;
use App\Dto\ChallongePlacing;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Service\AliasNormaliser;
use App\Service\ChallongeSnapshotFiles;
use App\Service\ChallongeSnapshotReader;
use App\Service\ChallongeStandingsResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The reader and the join, put to the eighteen brackets the league actually
 * played.
 *
 * Every other test here builds the shape it wants. This one reads the tracked
 * snapshots in `var/data/challonge/` and the placement lists in
 * `var/data/imports/` that were typed in by hand at the time, and asserts that
 * the two agree. That is the whole argument for importing from a bracket at
 * all: not that the new path is plausible, but that it reproduces sixteen
 * events somebody already checked.
 *
 * The numbers are written out rather than derived, because a number that
 * derived itself from the same files would agree with anything. Capturing a
 * nineteenth bracket will fail this test, and updating the counts is the
 * point at which somebody looks at what changed.
 */
final class CapturedBracketsTest extends TestCase
{
    private const BRACKETS = 18;

    private const MATCHES = 1010;

    private const MATCHES_PLAYED = 998;

    /**
     * The two 2v2 events are excluded from these: their entrants are teams, so
     * nothing in them is a blader's result.
     */
    private const MATCHES_PLAYED_BY_BLADERS = 947;

    /**
     * The same matches with the third-place playoffs taken out — the figure
     * that reconciles with the league's own count of what has been played.
     */
    private const MATCHES_THAT_DECIDED_SOMETHING = 933;

    /**
     * Every way a bracket has ever spelled an entrant, counted once each.
     * Team names and Challonge's own `bye` are in here too, because a snapshot
     * transcribes what the bracket said rather than what we would like it to
     * have said.
     */
    private const DISTINCT_SPELLINGS = 207;

    /**
     * The same spellings with case, punctuation and `(invitation pending)`
     * folded away. Seventy-eight of them turn out to be a spelling already in
     * the list; the gap between what is left and seventy-six bladers is what
     * the alias table is for.
     */
    private const SPELLINGS_AFTER_FOLDING = 129;

    /**
     * How many of the differences below are mechanical. Eight of twenty-six —
     * so folding is worth doing and is nowhere near enough on its own.
     */
    private const ALIASES_FOLDING_CATCHES = 8;

    private const STANDINGS_ROWS = 482;

    private const ROWS_JOINED_BY_MATCH_IDS = 377;

    private const ROWS_JOINED_BY_NAME = 105;

    private const EVENTS = 16;

    private const PLACEMENTS = 160;

    private const PLACEMENTS_NAMED_THE_SAME_WAY = 118;

    private const EVENTS_WITH_A_CUT = 14;

    /**
     * The two 2v2 events, named here because nothing in a snapshot says which
     * they are: `is_team` is false in all eighteen, the module store not
     * carrying the flag the fetch looks for.
     *
     * That is settled rather than outstanding. A team event is something the
     * importer is told, not something a bracket is asked — the rosters behind
     * the team names have to be supplied by hand whatever happens, so the same
     * step declares both. testTheTeamEventsAreTheOnesImportedTwice keeps this
     * list honest in the meantime: a 2v2 event is the only reason two import
     * lines ever point at one bracket.
     */
    private const TEAM_EVENTS = ['uhxii7az', 'ivanixk6'];

    /**
     * Every place a bracket spells a blader differently from the name they were
     * imported under, folded to lower case because the comparison is.
     *
     * These are the alias table's work (#50) and not this ticket's. What this
     * test proves is that they are the *only* differences: 118 of the 160
     * placements name the same person the same way, the remaining 42 sit at the
     * right rank under one of these spellings, and nothing else moved.
     */
    private const KNOWN_ALIASES = [
        'belti = ilbelti',
        'bladerz = bladerzmlt',
        'derius = derius_x',
        'derius = deriusx',
        'gerada46 = gerada 46',
        'giglio = giglio15 (invitation pending)',
        'guzman93 = guzman',
        'guzman93 = guzman (invitation pending)',
        'il-karm = karm',
        'jean = jean (invitation pending)',
        'kaori = kaori_x',
        'lanzjan = anzjan',
        'lanzjan = l-anzjan',
        'markinu = markinu (invitation pending)',
        'markulegend = maarkulegend',
        'markulegend = marku legend',
        'markulegend = markulegend (invitation pending)',
        'myers = myers6',
        'myers = myers6 (invitation pending) (invitation pending)',
        'obelix = obelisk',
        'ripnburst = rip n\' burst',
        'ripnburst = rip_n_burst',
        'rizzler = the rizzler',
        'sanya = sanya0207',
        'sk3lli = sk3llii',
        'sk3lli = skelli',
    ];

    private ChallongeSnapshotReader $reader;

    private ChallongeStandingsResolver $resolver;

    private AliasNormaliser $normaliser;

    /**
     * @var array<string, ChallongeSnapshot>
     */
    private array $snapshots;

    protected function setUp(): void
    {
        parent::setUp();

        $kernel = $this->createStub(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->projectDir());

        $this->reader = new ChallongeSnapshotReader(new ChallongeSnapshotFiles($kernel));
        $this->resolver = new ChallongeStandingsResolver();
        $this->normaliser = new AliasNormaliser();
        $this->snapshots = $this->readEveryCapturedBracket();
    }

    public function testEveryCapturedBracketReadsBack(): void
    {
        self::assertCount(self::BRACKETS, $this->snapshots);

        foreach ($this->snapshots as $slug => $snapshot) {
            self::assertSame($slug, $snapshot->slug, 'A snapshot is filed under a slug that is not its own.');
            self::assertNotSame([], $snapshot->stages, sprintf('The snapshot for "%s" has no stages.', $slug));
            self::assertTrue($snapshot->hasStandings(), sprintf('The snapshot for "%s" has no standings.', $slug));
        }
    }

    public function testItHoldsEveryMatchTheBracketsRecorded(): void
    {
        $matches = 0;
        $played = 0;

        foreach ($this->snapshots as $snapshot) {
            $matches += $snapshot->matchCount();
            $played += $snapshot->playedMatchCount();
        }

        self::assertSame(self::MATCHES, $matches);
        self::assertSame(self::MATCHES_PLAYED, $played, 'The difference is the forfeits and the 2v2 bracket that was never finished.');
    }

    public function testItHoldsEveryMatchABladerPlayed(): void
    {
        $played = 0;
        $deciding = 0;

        foreach ($this->snapshots as $slug => $snapshot) {
            if (in_array($slug, self::TEAM_EVENTS, true)) {
                continue;
            }

            foreach ($snapshot->stages as $stage) {
                foreach ($stage->playedMatches() as $match) {
                    ++$played;
                    $deciding += $match->consolation ? 0 : 1;
                }
            }
        }

        self::assertSame(self::MATCHES_PLAYED_BY_BLADERS, $played);
        self::assertSame(self::MATCHES_THAT_DECIDED_SOMETHING, $deciding, 'The difference is one third-place playoff per cut.');
    }

    /**
     * The join, over every standings row there is. Two thirds of them are
     * settled by the match-id intersection; the rest are the rows it cannot
     * settle — a first-round exit with one match to its name, and every row of
     * a one-stage bracket, whose standings table carries no match history at
     * all.
     */
    public function testEveryStandingsRowFindsTheEntrantItIsAbout(): void
    {
        $joins = [ChallongeJoin::MatchIds->value => 0, ChallongeJoin::Name->value => 0];
        $unresolved = [];

        foreach ($this->placings() as $slug => $placings) {
            foreach ($placings as $placing) {
                if (!$placing->isResolved()) {
                    $unresolved[] = sprintf('%s rank %d (%s)', $slug, $placing->rank(), $placing->name() ?? 'nobody');

                    continue;
                }

                ++$joins[$placing->join->value];
            }
        }

        self::assertSame([], $unresolved, 'Every standings row should reach an entrant.');
        self::assertSame(self::STANDINGS_ROWS, array_sum($joins));
        self::assertSame(self::ROWS_JOINED_BY_MATCH_IDS, $joins[ChallongeJoin::MatchIds->value]);
        self::assertSame(self::ROWS_JOINED_BY_NAME, $joins[ChallongeJoin::Name->value]);
    }

    /**
     * A join that handed two rows the same entrant would be inventing a result
     * for somebody, and would do it quietly.
     */
    public function testNoTwoRowsClaimTheSameEntrant(): void
    {
        foreach ($this->placings() as $slug => $placings) {
            /*
             * Unresolved rows are not claims and must not read as colliding
             * ones — two nulls would fail this with a message about the wrong
             * thing, on exactly the run where the message matters.
             */
            $claimed = array_values(array_filter(array_map(
                static fn (ChallongePlacing $placing): ?int => $placing->participant?->id,
                $placings,
            )));

            self::assertCount(
                count(array_unique($claimed, \SORT_REGULAR)),
                $claimed,
                sprintf('Two rows of "%s" were joined to the same entrant.', $slug),
            );
        }
    }

    /**
     * The criterion the whole import rests on: rank *n* of the bracket is line
     * *n* of the file somebody typed by hand, for all sixteen events.
     */
    public function testEveryEventReproducesTheOrderItWasImportedIn(): void
    {
        $events = 0;
        $placements = 0;
        $sameName = 0;
        $differences = [];

        foreach ($this->events() as $event) {
            if (in_array($event['slug'], self::TEAM_EVENTS, true)) {
                continue;
            }

            ++$events;

            $imported = $this->importedPlacements($event['file']);
            $bracket = $this->resolver->finishingOrder($this->snapshots[$event['slug']]);

            self::assertGreaterThanOrEqual(
                count($imported),
                count($bracket),
                sprintf('The bracket "%s" ranks fewer people than were imported from it.', $event['slug']),
            );

            foreach ($imported as $position => $name) {
                ++$placements;

                $ranked = $bracket[$position]->name();

                self::assertSame(
                    $position + 1,
                    $bracket[$position]->rank(),
                    sprintf('Line %d of "%s" is not rank %d of the bracket.', $position + 1, $event['file'], $position + 1),
                );

                if ($this->fold($name) === $this->fold($ranked)) {
                    ++$sameName;

                    continue;
                }

                $differences[] = sprintf('%s = %s', $this->fold($name), $this->fold($ranked));
            }
        }

        $differences = array_values(array_unique($differences));
        sort($differences);

        self::assertSame(self::EVENTS, $events);
        self::assertSame(self::PLACEMENTS, $placements);
        self::assertSame(self::PLACEMENTS_NAMED_THE_SAME_WAY, $sameName);
        self::assertSame(self::KNOWN_ALIASES, $differences, 'A blader is spelled a way the alias table has not been told about.');
    }

    /**
     * How far the mechanical half of the alias problem gets, measured on the
     * real thing rather than asserted about.
     *
     * Two hundred and seven spellings fold to a hundred and twenty-nine, which
     * is the argument for normalising and against stopping there: the league
     * has about seventy-six bladers, so fifty-odd of these are still two names
     * for one person with nothing in the strings to say so.
     */
    public function testFoldingSpellingsGetsPartOfTheWayAndNoFurther(): void
    {
        $spellings = [];

        foreach ($this->snapshots as $snapshot) {
            foreach ($snapshot->stages as $stage) {
                foreach ($stage->participants as $participant) {
                    $spellings[$participant->name] = true;
                }
            }
        }

        $folded = array_unique(array_map(
            fn (string $spelling): string => $this->normaliser->normalise($spelling),
            array_map(strval(...), array_keys($spellings)),
        ));

        self::assertCount(self::DISTINCT_SPELLINGS, $spellings);
        self::assertCount(self::SPELLINGS_AFTER_FOLDING, $folded);
    }

    /**
     * The same point made against the differences that actually cost somebody
     * an evening: eight of the twenty-six are case, spacing or an invitation
     * nobody accepted, and the other eighteen are knowledge. `Anzjan` is
     * `Lanzjan` and `Obelisk` is not `Obelix`, and no rule tells them apart.
     */
    public function testTheAliasTableIsWhatFoldingCannotDo(): void
    {
        $mechanical = 0;

        foreach (self::KNOWN_ALIASES as $difference) {
            [$imported, $bracket] = explode(' = ', $difference);

            if ($this->normaliser->normalise($imported) === $this->normaliser->normalise($bracket)) {
                ++$mechanical;
            }
        }

        self::assertSame(self::ALIASES_FOLDING_CATCHES, $mechanical);
        self::assertGreaterThan(
            $mechanical,
            count(self::KNOWN_ALIASES) - $mechanical,
            'Most of the differences should need a person, or the alias table would not be worth building.',
        );
    }

    /**
     * The other rule an import applies today: the bonus goes to whoever won the
     * cut. The playoff for third is played after the final, so a bracket read
     * back to front names the wrong person.
     */
    public function testEveryCutNamesTheKnockoutWinnerItWasImportedWith(): void
    {
        $cuts = 0;

        foreach ($this->events() as $event) {
            $winner = $this->snapshots[$event['slug']]->knockoutWinner();

            if (null === $event['knockout']) {
                self::assertNull(
                    $winner,
                    sprintf('The bracket "%s" was imported with no knockout winner, but names %s.', $event['slug'], $winner->name ?? ''),
                );

                continue;
            }

            ++$cuts;

            self::assertNotNull($winner, sprintf('The bracket "%s" names nobody as its knockout winner.', $event['slug']));
            self::assertTrue(
                $this->isTheSameBlader($event['knockout'], $winner->name),
                sprintf('"%s" won the cut in "%s", which was imported as "%s".', $winner->name, $event['slug'], $event['knockout']),
            );
        }

        self::assertSame(self::EVENTS_WITH_A_CUT, $cuts);
    }

    /**
     * Keeps TEAM_EVENTS honest. A 2v2 event is one bracket that was imported
     * twice, once for each half of every team, and it is the only reason two
     * import lines ever point at the same bracket.
     */
    public function testTheTeamEventsAreTheOnesImportedTwice(): void
    {
        $imports = [];

        foreach ($this->events() as $event) {
            $imports[$event['slug']] = ($imports[$event['slug']] ?? 0) + 1;
        }

        self::assertSame(self::TEAM_EVENTS, array_keys(array_filter(
            $imports,
            static fn (int $times): bool => $times > 1,
        )));
    }

    /**
     * @return array<string, list<ChallongePlacing>>
     */
    private function placings(): array
    {
        $placings = [];

        foreach ($this->snapshots as $slug => $snapshot) {
            $placings[$slug] = array_merge(...array_map(
                fn (ChallongeStage $stage): array => $this->resolver->resolve($stage),
                $snapshot->stages,
            ));
        }

        return $placings;
    }

    /**
     * @return array<string, ChallongeSnapshot>
     */
    private function readEveryCapturedBracket(): array
    {
        $snapshots = [];

        foreach ((array) glob($this->projectDir().'/var/data/challonge/*.json') as $path) {
            self::assertIsString($path);

            $snapshots[basename($path, '.json')] = $this->reader->readFile($path);
        }

        return $snapshots;
    }

    /**
     * Every tournament the league has imported, read out of `repeat.sh` — the
     * record of what was typed by hand, and so the only honest thing to check a
     * bracket against. Events whose bracket has not been captured yet are left
     * out.
     *
     * @return list<array{file: string, slug: string, knockout: ?string}>
     */
    private function events(): array
    {
        $events = [];

        foreach (explode("\n", (string) file_get_contents($this->projectDir().'/repeat.sh')) as $line) {
            if (!str_contains($line, 'app:import-tournament')) {
                continue;
            }

            preg_match("/imports\/([^']+)\.txt'/", $line, $file);
            preg_match("/--challonge='([^']+)'/", $line, $url);
            preg_match("/--knockout='([^']+)'/", $line, $knockout);

            self::assertArrayHasKey(1, $file, sprintf('This import line names no placement file: %s', trim($line)));

            $slug = basename(rtrim($url[1] ?? '', '/'));

            if (!isset($this->snapshots[$slug])) {
                continue;
            }

            $events[] = [
                'file' => $file[1],
                'slug' => $slug,
                'knockout' => $knockout[1] ?? null,
            ];
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function importedPlacements(string $file): array
    {
        $lines = (array) file($this->projectDir().'/var/data/imports/'.$file.'.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        return array_values(array_filter(array_map(
            static fn (mixed $line): string => trim((string) $line),
            $lines,
        )));
    }

    private function isTheSameBlader(string $imported, ?string $bracket): bool
    {
        return $this->fold($imported) === $this->fold($bracket)
            || in_array(sprintf('%s = %s', $this->fold($imported), $this->fold($bracket)), self::KNOWN_ALIASES, true);
    }

    private function fold(?string $name): string
    {
        return mb_strtolower(trim($name ?? ''));
    }

    private function projectDir(): string
    {
        return \dirname(__DIR__, 2);
    }
}
