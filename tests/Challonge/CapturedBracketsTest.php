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
use App\Service\TeamListParser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The reader and the join, put to the twenty brackets the league actually
 * played.
 *
 * Every other test here builds the shape it wants. This one reads the tracked
 * snapshots in `var/data/challonge/` and the placement lists in
 * `var/data/imports/` that were typed in by hand at the time, and asserts that
 * the two agree. That is the whole argument for importing from a bracket at
 * all: not that the new path is plausible, but that it reproduces eighteen
 * events somebody already checked.
 *
 * The numbers are written out rather than derived, because a number that
 * derived itself from the same files would agree with anything. Capturing a
 * twenty-first bracket will fail this test, and updating the counts is the
 * point at which somebody looks at what changed.
 */
final class CapturedBracketsTest extends TestCase
{
    private const BRACKETS = 20;

    private const MATCHES = 1150;

    private const MATCHES_PLAYED = 1138;

    /**
     * The two 2v2 events are excluded from these: their entrants are teams, so
     * nothing in them is a blader's result.
     */
    private const MATCHES_PLAYED_BY_BLADERS = 1087;

    /**
     * The same matches with the third-place playoffs taken out — the figure
     * that reconciles with the league's own count of what has been played.
     */
    private const MATCHES_THAT_DECIDED_SOMETHING = 1071;

    /**
     * Every way a bracket has ever spelled an entrant, counted once each.
     * Team names and Challonge's own `bye` are in here too, because a snapshot
     * transcribes what the bracket said rather than what we would like it to
     * have said.
     */
    private const DISTINCT_SPELLINGS = 220;

    /**
     * The same spellings with case, punctuation and `(invitation pending)`
     * folded away. Seventy-eight of them turn out to be a spelling already in
     * the list; the gap between what is left and seventy-six bladers is what
     * the alias table is for.
     */
    private const SPELLINGS_AFTER_FOLDING = 142;

    /**
     * How many of the differences below are mechanical. Eight of twenty-nine —
     * so folding is worth doing and is nowhere near enough on its own.
     */
    private const ALIASES_FOLDING_CATCHES = 8;

    /**
     * The rows `app:bootstrap-aliases` writes out of all this.
     *
     * Fewer than the twenty-nine differences below, and the gap is the point of
     * both halves of the alias problem sitting next to each other: eight of the
     * differences fold away mechanically and need no row at all, and three of
     * the spellings that remain are one row each spelled two ways —
     * `Derius_X` and `DeriusX`, `Guzman` with and without its invitation,
     * `Myers6` with two of them. What is left is eighteen assertions somebody
     * would otherwise have typed.
     */
    private const SEEDED_ALIASES = 18;

    private const STANDINGS_ROWS = 552;

    private const ROWS_JOINED_BY_MATCH_IDS = 438;

    private const ROWS_JOINED_BY_NAME = 114;

    private const EVENTS = 18;

    private const PLACEMENTS = 180;

    private const PLACEMENTS_NAMED_THE_SAME_WAY = 131;

    private const EVENTS_WITH_A_CUT = 16;

    /**
     * The two 2v2 events, named here because nothing in a snapshot says which
     * they are: `is_team` is false in all twenty, the module store not
     * carrying the flag the fetch looks for.
     *
     * That is settled rather than outstanding. A team event is something the
     * importer is told, not something a bracket is asked — the rosters behind
     * the team names have to be supplied by hand whatever happens, so the same
     * step declares both, and `--team` in `repeat.sh` is where it is said.
     * testTheTeamEventsAreTheOnesDeclaredAsTeamEvents keeps this list honest.
     */
    private const TEAM_EVENTS = ['uhxii7az', 'ivanixk6'];

    /**
     * The entrants of those two events, `bye` excluded — it is a slot in a
     * bracket rather than somebody who turned up.
     */
    private const BYE = 'bye';

    private const TEAMS = 18;

    /**
     * The bladers in them. Two per team for all but `JG` and `melhina`, which
     * nobody has claimed: sixteen claimed teams, two apiece.
     */
    private const TEAM_MEMBERS = 32;

    /**
     * Every place a bracket spells a blader differently from the name they were
     * imported under, folded to lower case because the comparison is.
     *
     * These are the alias table's work (#50) and not this ticket's. What this
     * test proves is that they are the *only* differences: 131 of the 180
     * placements name the same person the same way, the remaining 49 sit at the
     * right rank under one of these spellings, and nothing else moved.
     */
    private const KNOWN_ALIASES = [
        'belti = ilbelti',
        'bladerz = bladerzmlt',
        'derius = derius_x',
        'derius = deriusx',
        'faenza = fajjenza',
        'federico = federiko',
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
        'piyus = peyus',
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

    /**
     * Offline replay is a property of the ledger, not of whoever happens to
     * have fetched a bracket before running it. Every Challonge URL therefore
     * has to name the tracked snapshot that belongs to the same slug.
     */
    public function testEveryChallongeImportReplaysFromItsSnapshot(): void
    {
        $imports = 0;

        foreach (explode("\n", (string) file_get_contents($this->projectDir().'/repeat.sh')) as $line) {
            if (!str_contains($line, 'app:import-tournament') || !str_contains($line, '--challonge=')) {
                continue;
            }

            ++$imports;
            preg_match("/--challonge='([^']+)'/", $line, $url);
            self::assertArrayHasKey(1, $url, sprintf('This import line names no Challonge URL: %s', trim($line)));

            $slug = basename(rtrim($url[1], '/'));
            $path = sprintf('/app/var/data/challonge/%s.json', $slug);

            self::assertStringContainsString(
                sprintf("--snapshot='%s'", $path),
                $line,
                sprintf('The import for "%s" does not explicitly replay its snapshot.', $slug),
            );
            self::assertFileExists($this->projectDir().'/var/data/challonge/'.$slug.'.json');
        }

        self::assertSame(self::BRACKETS, $imports);
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
     * Two hundred and twenty spellings fold to a hundred and forty-two, which
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
     * an evening: eight of the twenty-nine are case, spacing or an invitation
     * nobody accepted, and the other twenty-one are knowledge. `Anzjan` is
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
     * What the seeding pass has to work with, counted off the corpus rather
     * than off its own output.
     *
     * Two things have to hold for `app:bootstrap-aliases` to write anything at
     * all. Every spelling the brackets use has to reach one blader and not two,
     * because a spelling two evenings disagree about is reported and never
     * filed. And the twenty-nine differences have to collapse to the eighteen
     * rows the alias table actually needs, because a row for a spelling the
     * normaliser already folds is a row nothing ever reads.
     */
    public function testTheAliasTableSeedsToEighteenRows(): void
    {
        $bladerPerSpelling = [];

        foreach (self::KNOWN_ALIASES as $difference) {
            [$imported, $bracket] = explode(' = ', $difference);

            $spelling = $this->normaliser->normalise($bracket);

            if ($this->normaliser->normalise($imported) === $spelling) {
                continue;
            }

            $bladerPerSpelling[$spelling][$this->normaliser->normalise($imported)] = true;
        }

        foreach ($bladerPerSpelling as $spelling => $bladers) {
            self::assertCount(
                1,
                $bladers,
                sprintf('"%s" is spelled the same way by two bladers, so nothing can be seeded from it.', $spelling),
            );
        }

        self::assertCount(self::SEEDED_ALIASES, $bladerPerSpelling);
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
     * Keeps TEAM_EVENTS honest. A team event is declared rather than detected,
     * so the only record of which events are 2v2 is the `--team` on their
     * import line, and this is the assertion that the list above and that flag
     * have not drifted apart.
     */
    public function testTheTeamEventsAreTheOnesDeclaredAsTeamEvents(): void
    {
        self::assertSame(self::TEAM_EVENTS, array_values(array_map(
            static fn (array $event): string => $event['slug'],
            array_filter(
                $this->events(),
                static fn (array $event): bool => $event['team'],
            ),
        )));
    }

    /**
     * The team events' half of testEveryEventReproducesTheOrderItWasImportedIn.
     *
     * The rosters were reconstructed from the paired Player A / Player B files
     * those two events used to be imported from, and those files are gone —
     * the whole point of the change is that a 2v2 event is one tournament. So
     * what is left to check them against is the bracket itself, which is the
     * durable half anyway: line *n* of the roster is the entrant Challonge
     * ranked *n*.
     *
     * Folded through the normaliser rather than by case alone, because the
     * bracket writes `legion ()` and `infernal rage (invitation pending)` and
     * the roster writes the names people used.
     */
    public function testEveryTeamEventReproducesTheOrderItsRosterWasTypedIn(): void
    {
        $events = 0;
        $teams = 0;
        $members = 0;

        foreach ($this->events() as $event) {
            if (!$event['team']) {
                continue;
            }

            ++$events;

            $roster = (new TeamListParser())->parse(
                (string) file_get_contents($this->projectDir().'/var/data/imports/'.$event['file'].'.txt'),
            );

            $stage = $this->snapshots[$event['slug']]->rankingStage();

            self::assertNotNull($stage, sprintf('The bracket "%s" ranks nobody.', $event['slug']));

            $ranked = $stage->standings;

            self::assertCount(
                count($ranked),
                $roster,
                sprintf('The roster for "%s" does not list every entrant the bracket ranked.', $event['slug']),
            );

            foreach ($roster as $position => $team) {
                self::assertSame(
                    $position + 1,
                    $ranked[$position]->rank,
                    sprintf('Line %d of "%s" is not rank %d of the bracket.', $position + 1, $event['file'], $position + 1),
                );

                self::assertSame(
                    $this->normaliser->normalise($ranked[$position]->name ?? ''),
                    $this->normaliser->normalise($team->teamName),
                    sprintf('Line %d of "%s" is not the entrant the bracket ranked there.', $position + 1, $event['file']),
                );

                if (self::BYE === $this->normaliser->normalise($team->teamName)) {
                    continue;
                }

                ++$teams;
                $members += count($team->memberNames);
            }
        }

        self::assertCount($events, self::TEAM_EVENTS);
        self::assertSame(self::TEAMS, $teams);
        self::assertSame(self::TEAM_MEMBERS, $members);
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
     * @return list<array{file: string, slug: string, knockout: ?string, team: bool}>
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
                'team' => str_contains($line, ' --team'),
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
