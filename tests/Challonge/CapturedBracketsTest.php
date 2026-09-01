<?php

declare(strict_types=1);

namespace App\Tests\Challonge;

use App\Dto\ChallongeJoin;
use App\Dto\ChallongeMatch;
use App\Dto\ChallongePlacing;
use App\Dto\ChallongeSnapshot;
use App\Dto\ChallongeStage;
use App\Service\AliasNormaliser;
use App\Service\ChallongeSnapshotFiles;
use App\Service\ChallongeSnapshotReader;
use App\Service\ChallongeStandingsResolver;
use App\Service\TeamListParser;
use App\Tests\Support\ReadsTheLeaguesCorpus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * The reader and the join, put to every bracket the league has actually
 * played.
 *
 * Every other test here builds the shape it wants. This one reads the tracked
 * snapshots in `var/data/challonge/` and the placement lists in
 * `var/data/imports/` that were typed in by hand at the time, and asserts that
 * the two agree. That is the whole argument for importing from a bracket at
 * all: not that the new path is plausible, but that it reproduces every event
 * somebody already checked.
 *
 * Nothing here counts the corpus. It used to — twenty brackets, 1150 matches,
 * 552 standings rows, twenty-nine spelling differences listed out by hand —
 * on the argument that a derived number would agree with anything. The
 * argument was right about derived numbers and wrong about the cost: an
 * evening's results are imported twice a week, and every one of those
 * evenings failed eleven tests that nothing was wrong with. A suite that
 * cries wolf on the league's ordinary Tuesday is not protecting anything,
 * because the only available response is to re-run it and paste the new
 * totals in.
 *
 * What replaced them are cross-checks, which is the thing a written-out total
 * was standing in for. Every number still asserted here has two independent
 * sides: the resolver's output against the standings it read, the ledger's
 * import lines against the snapshot directory, a bracket's spelling of
 * somebody against the alias table that says who they are, `matchCount()`
 * against a walk of the stages it counts. Those disagree the moment the code
 * between the two sides changes, and stay quiet when the league plays another
 * tournament — which is the behaviour that was wanted from the totals.
 *
 * @see ReadsTheLeaguesCorpus for how `repeat.sh` is read, and why it is the
 *      alias table's home rather than a list in this file
 */
final class CapturedBracketsTest extends TestCase
{
    use ReadsTheLeaguesCorpus;

    /**
     * Challonge's own name for a slot in a bracket rather than somebody who
     * turned up.
     */
    private const BYE = 'bye';

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
        $kernel->method('getProjectDir')->willReturn($this->corpusRoot());

        $this->reader = new ChallongeSnapshotReader(new ChallongeSnapshotFiles($kernel));
        $this->resolver = new ChallongeStandingsResolver();
        $this->normaliser = new AliasNormaliser();
        $this->snapshots = $this->readEveryCapturedBracket();
    }

    public function testEveryCapturedBracketReadsBack(): void
    {
        self::assertNotSame([], $this->snapshots, 'No bracket has been captured at all.');

        foreach ($this->snapshots as $slug => $snapshot) {
            self::assertSame($slug, $snapshot->slug, 'A snapshot is filed under a slug that is not its own.');
            self::assertNotSame([], $snapshot->stages, sprintf('The snapshot for "%s" has no stages.', $slug));
            self::assertTrue($snapshot->hasStandings(), sprintf('The snapshot for "%s" has no standings.', $slug));
        }
    }

    /**
     * The two halves of the corpus name each other.
     *
     * This is what counting the brackets was for. A snapshot nothing imports
     * is a file somebody fetched and forgot; an import whose snapshot is not
     * tracked cannot be replayed offline. Either one is worth failing over,
     * and neither has anything to do with how many brackets there are.
     */
    public function testTheLedgerAndTheSnapshotDirectoryNameTheSameBrackets(): void
    {
        $imported = array_values(array_unique(array_filter(array_map(
            static fn (array $event): string => $event['slug'],
            $this->importedEvents(),
        ))));

        $captured = array_keys($this->snapshots);

        sort($imported);
        sort($captured);

        self::assertSame($imported, $captured);
    }

    /**
     * Offline replay is a property of the ledger, not of whoever happens to
     * have fetched a bracket before running it. Every Challonge URL therefore
     * has to name the tracked snapshot that belongs to the same slug.
     */
    public function testEveryChallongeImportReplaysFromItsSnapshot(): void
    {
        $imports = 0;

        foreach ($this->ledger() as $line) {
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
            self::assertFileExists($this->corpusRoot().'/var/data/challonge/'.$slug.'.json');
        }

        self::assertCount($imports, $this->snapshots);
    }

    /**
     * The counters against the stages they count.
     *
     * `matchCount()` and `playedMatchCount()` walk the snapshot themselves, so
     * a change to either that stopped agreeing with a plain walk of
     * `$stage->matches` would be invisible to everything else here.
     */
    public function testTheMatchCountersAgreeWithTheStagesTheyCount(): void
    {
        foreach ($this->snapshots as $slug => $snapshot) {
            $matches = 0;
            $played = 0;

            foreach ($snapshot->stages as $stage) {
                $matches += count($stage->matches);
                $played += count($stage->playedMatches());
            }

            self::assertSame($matches, $snapshot->matchCount(), sprintf('"%s" counts its matches two different ways.', $slug));
            self::assertSame($played, $snapshot->playedMatchCount(), sprintf('"%s" counts its played matches two different ways.', $slug));
            self::assertGreaterThan(0, $matches, sprintf('"%s" holds no matches at all.', $slug));
            self::assertLessThanOrEqual($matches, $played);

            self::assertSame(
                $snapshot->participantCount(),
                $this->total($snapshot->stages, static fn (ChallongeStage $stage): int => count($stage->participants)),
                sprintf('"%s" counts its entrants two different ways.', $slug),
            );
            self::assertSame(
                $snapshot->standingsCount(),
                $this->total($snapshot->stages, static fn (ChallongeStage $stage): int => count($stage->standings)),
                sprintf('"%s" counts its standings rows two different ways.', $slug),
            );
        }
    }

    /**
     * What a played match is, asserted rather than counted.
     *
     * The old figure here was the one that reconciled with the league's own
     * count of what had been played, and the reconciliation it was really
     * making is `ArchivedBracketsTest`'s: archived rows against the snapshot
     * they came from. What is left is the shape every played match has.
     */
    public function testEveryPlayedMatchNamesTwoEntrantsAndAWinner(): void
    {
        foreach ($this->snapshots as $slug => $snapshot) {
            foreach ($snapshot->stages as $stage) {
                foreach ($stage->playedMatches() as $match) {
                    $where = sprintf('Match %d of "%s"', $match->id, $slug);

                    self::assertNotNull($match->player1Id, $where.' was played by nobody.');
                    self::assertNotNull($match->player2Id, $where.' was played against nobody.');

                    /*
                     * A winner is not required. Challonge shows a handful of
                     * complete 0-0 matches with nobody named, and
                     * ChallongeMatch::wasPlayed() counts them on purpose — a
                     * snapshot does not get to disagree with the bracket it
                     * transcribed. What must hold is that a named winner is
                     * one of the two who played.
                     */
                    if (null !== $match->winnerId) {
                        self::assertContains(
                            $match->winnerId,
                            [$match->player1Id, $match->player2Id],
                            $where.' was won by somebody who did not play in it.',
                        );
                    }
                }

                $consolation = array_filter(
                    $stage->matches,
                    static fn (ChallongeMatch $match): bool => $match->consolation,
                );

                self::assertLessThanOrEqual(
                    1,
                    count($consolation),
                    sprintf('A stage of "%s" holds more than one playoff for third.', $slug),
                );
            }
        }
    }

    /**
     * The join, over every standings row there is. Two thirds of them are
     * settled by the match-id intersection; the rest are the rows it cannot
     * settle — a first-round exit with one match to its name, and every row of
     * a one-stage bracket, whose standings table carries no match history at
     * all.
     *
     * The total is checked against the standings the resolver was handed
     * rather than against a number: a resolver that quietly dropped rows would
     * pass an `assertSame([], $unresolved)` on its own.
     */
    public function testEveryStandingsRowFindsTheEntrantItIsAbout(): void
    {
        $joins = [ChallongeJoin::MatchIds->value => 0, ChallongeJoin::Name->value => 0];
        $unresolved = [];
        $rows = 0;

        foreach ($this->placings() as $slug => $placings) {
            $rows += $this->snapshots[$slug]->standingsCount();

            foreach ($placings as $placing) {
                if (!$placing->isResolved()) {
                    $unresolved[] = sprintf('%s rank %d (%s)', $slug, $placing->rank(), $placing->name() ?? 'nobody');

                    continue;
                }

                ++$joins[$placing->join->value];
            }
        }

        self::assertSame([], $unresolved, 'Every standings row should reach an entrant.');
        self::assertSame($rows, array_sum($joins), 'The resolver returned a different number of rows than it was given.');
        self::assertGreaterThan(0, $joins[ChallongeJoin::MatchIds->value], 'Nothing joined on match ids, which is the join that carries the corpus.');
        self::assertGreaterThan(0, $joins[ChallongeJoin::Name->value], 'Nothing joined on a name, so the fallback is no longer exercised.');
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
     * *n* of the file somebody typed by hand, for every solo event.
     *
     * Where the two spell somebody differently, the alias table has to be the
     * thing that says they are the same person. That list used to live in this
     * file and had to be extended by hand every time a bracket spelled a
     * regular a new way; it now comes off the `app:alias add` lines in
     * `repeat.sh`, which the import screen already writes when somebody
     * answers the name question. So a new spelling is declared once, where the
     * site will actually read it, instead of twice.
     */
    public function testEveryEventReproducesTheOrderItWasImportedIn(): void
    {
        $events = 0;
        $undeclared = [];

        foreach ($this->solo() as $event) {
            ++$events;

            $imported = $this->importedPlacements($event['file']);
            $bracket = $this->resolver->finishingOrder($this->snapshots[$event['slug']]);

            self::assertGreaterThanOrEqual(
                count($imported),
                count($bracket),
                sprintf('The bracket "%s" ranks fewer people than were imported from it.', $event['slug']),
            );

            foreach ($imported as $position => $name) {
                $ranked = $bracket[$position]->name();

                self::assertSame(
                    $position + 1,
                    $bracket[$position]->rank(),
                    sprintf('Line %d of "%s" is not rank %d of the bracket.', $position + 1, $event['file'], $position + 1),
                );

                if ($this->isTheSameBlader($name, $ranked)) {
                    continue;
                }

                $undeclared[] = sprintf('%s = %s (rank %d of %s)', $this->fold($name), $this->fold($ranked), $position + 1, $event['slug']);
            }
        }

        $undeclared = array_values(array_unique($undeclared));
        sort($undeclared);

        self::assertGreaterThan(0, $events, 'No solo event was checked at all.');
        self::assertSame(
            [],
            $undeclared,
            'A blader is spelled a way the alias table has not been told about. Each of these needs an app:alias add line in repeat.sh, or is two people and needs an app:alias reject.',
        );
    }

    /**
     * How far the mechanical half of the alias problem gets, measured on the
     * real thing rather than asserted about.
     *
     * Folding case, punctuation and `(invitation pending)` collapses a good
     * number of spellings and nowhere near all of them: what is left is still
     * comfortably more entries than the league has bladers, so fifty-odd of
     * them are two names for one person with nothing in the strings to say so.
     * That gap is the argument for the alias table, and it is asserted as a
     * gap rather than as two totals that move every time somebody new turns
     * up.
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

        self::assertLessThan(
            count($spellings),
            count($folded),
            'Folding collapsed nothing, so the normaliser has stopped doing its half of the job.',
        );
        self::assertGreaterThan(
            count($this->bladersDeclared()),
            count($folded),
            'Folding alone got down to one entry per blader, which it cannot honestly do — check the normaliser has not started folding two people together.',
        );
    }

    /**
     * The same point made against the differences that actually cost somebody
     * an evening: some are case, spacing or an invitation nobody accepted, and
     * the rest are knowledge. `Anzjan` is `Lanzjan` and `Obelisk` is not
     * `Obelix`, and no rule tells them apart.
     */
    public function testTheAliasTableIsWhatFoldingCannotDo(): void
    {
        $mechanical = 0;
        $curated = 0;

        foreach ($this->spellingDifferences() as [$imported, $bracket]) {
            if ($this->normaliser->normalise($imported) === $this->normaliser->normalise($bracket)) {
                ++$mechanical;

                continue;
            }

            ++$curated;
        }

        self::assertGreaterThan(0, $mechanical, 'Nothing folds mechanically, so the normaliser is not earning its place.');
        self::assertGreaterThan(
            $mechanical,
            $curated,
            'Most of the differences should need a person, or the alias table would not be worth building.',
        );
    }

    /**
     * What `app:bootstrap-aliases` has to hold for it to write anything at
     * all: every spelling the brackets use reaches one blader and not two. A
     * spelling two evenings disagree about is reported and never filed.
     */
    public function testNoSpellingIsClaimedByTwoBladers(): void
    {
        $bladerPerSpelling = [];

        foreach ($this->spellingDifferences() as [$imported, $bracket]) {
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

        self::assertNotSame([], $bladerPerSpelling, 'No curated difference was found, so this is checking nothing.');
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

            /*
             * An unranked event awards no knockout bonus, so its import names
             * no winner however the bracket ended. The bracket is still
             * allowed to have had a cut, and usually did.
             */
            if ($event['unranked']) {
                self::assertNull(
                    $event['knockout'],
                    sprintf('The unranked event "%s" was imported with a knockout winner, which awards a bonus it cannot have.', $event['slug']),
                );

                continue;
            }

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

        self::assertGreaterThan(0, $cuts, 'No event with a cut was checked at all.');
    }

    /**
     * The team events' half of testEveryEventReproducesTheOrderItWasImportedIn.
     *
     * The rosters were reconstructed from the paired Player A / Player B files
     * those events used to be imported from, and those files are gone — the
     * whole point of the change is that a 2v2 event is one tournament. So what
     * is left to check them against is the bracket itself, which is the
     * durable half anyway: line *n* of the roster is the entrant Challonge
     * ranked *n*.
     *
     * Folded through the normaliser rather than by case alone, because the
     * bracket writes `legion ()` and `infernal rage (invitation pending)` and
     * the roster writes the names people used.
     */
    public function testEveryTeamEventReproducesTheOrderItsRosterWasTypedIn(): void
    {
        $teams = 0;

        foreach ($this->events() as $event) {
            if (!$event['team']) {
                continue;
            }

            $roster = (new TeamListParser())->parse(
                (string) file_get_contents($this->corpusRoot().'/var/data/imports/'.$event['file'].'.txt'),
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
            }
        }

        if ([] === $this->teamEventSlugs()) {
            self::markTestSkipped('The league has imported no 2v2 event.');
        }

        self::assertGreaterThan(0, $teams, 'No team was checked at all.');
    }

    /**
     * Every difference between the name an event was imported under and the
     * way its bracket spells the same person, as pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function spellingDifferences(): array
    {
        $differences = [];

        foreach ($this->solo() as $event) {
            $bracket = $this->resolver->finishingOrder($this->snapshots[$event['slug']]);

            foreach ($this->importedPlacements($event['file']) as $position => $name) {
                $ranked = $bracket[$position]->name() ?? '';

                if ($this->fold($name) === $this->fold($ranked)) {
                    continue;
                }

                $differences[$this->fold($name).' = '.$this->fold($ranked)] = [$name, $ranked];
            }
        }

        ksort($differences);

        return array_values($differences);
    }

    /**
     * @param list<ChallongeStage>          $stages
     * @param callable(ChallongeStage): int $count
     */
    private function total(array $stages, callable $count): int
    {
        return array_sum(array_map($count, $stages));
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

        foreach ((array) glob($this->corpusRoot().'/var/data/challonge/*.json') as $path) {
            self::assertIsString($path);

            $snapshots[basename($path, '.json')] = $this->reader->readFile($path);
        }

        return $snapshots;
    }

    /**
     * The imported events whose bracket has been captured. Events imported
     * from a bracket nobody snapshotted are nothing this test can check.
     *
     * @return list<array{file: string, slug: string, knockout: ?string, team: bool, unranked: bool}>
     */
    private function events(): array
    {
        return array_values(array_filter(
            $this->importedEvents(),
            fn (array $event): bool => isset($this->snapshots[$event['slug']]),
        ));
    }

    /**
     * @return list<array{file: string, slug: string, knockout: ?string, team: bool, unranked: bool}>
     */
    private function solo(): array
    {
        return array_values(array_filter(
            $this->events(),
            static fn (array $event): bool => !$event['team'],
        ));
    }

    /**
     * @return list<string>
     */
    private function importedPlacements(string $file): array
    {
        $lines = (array) file($this->corpusRoot().'/var/data/imports/'.$file.'.txt', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        return array_values(array_filter(array_map(
            static fn (mixed $line): string => trim((string) $line),
            $lines,
        )));
    }

    private function fold(?string $name): string
    {
        return mb_strtolower(trim($name ?? ''));
    }
}
