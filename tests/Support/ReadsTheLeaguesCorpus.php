<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\AliasNormaliser;

/**
 * `repeat.sh` read as what it is: the league's own record of every blader,
 * every alias and every import somebody typed.
 *
 * The corpus tests used to check the tracked snapshots against totals written
 * into the test — 1150 matches, 552 standings rows, twenty-nine spelling
 * differences listed by hand. The argument for that was a good one: a number
 * that derived itself from the same file it was checking would agree with
 * anything. The cost turned out to be worse. Results are imported twice a
 * week, and every one of those evenings failed eleven tests that nothing was
 * wrong with, which trains everybody to re-run the census and paste whatever
 * it prints — the opposite of looking at what changed.
 *
 * So the totals are gone and the cross-checks stay. Every number the corpus
 * tests still assert has *two* independent sides to it: the archived rows
 * against the snapshot they were written from, the resolver's output against
 * the standings it resolved, the ledger's import lines against the snapshot
 * directory, a bracket's spelling of somebody against the alias table that
 * says who they are. None of those agree with anything — they disagree the
 * moment the code between the two sides changes — and none of them care how
 * many brackets the league has played.
 */
trait ReadsTheLeaguesCorpus
{
    /**
     * @var array<string, string>|null
     */
    private ?array $aliasTable = null;

    /**
     * @var list<string>|null
     */
    private ?array $ledgerLines = null;

    /**
     * Named for what it is rather than `projectDir()`, which
     * `InteractsWithTheLedger` already declares as `protected static` — a
     * private non-static of the same name in a class that inherits it is a
     * fatal error rather than an override.
     */
    private function corpusRoot(): string
    {
        return \dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private function ledger(): array
    {
        return $this->ledgerLines ??= array_values(array_filter(
            explode("\n", (string) file_get_contents($this->corpusRoot().'/repeat.sh')),
            static fn (string $line): bool => '' !== trim($line),
        ));
    }

    /**
     * The single-quoted arguments of a ledger line, in order, unescaped the
     * way the shell would.
     *
     * `escapeshellarg()` writes an apostrophe as `'\''`, which closes the
     * quoting, escapes the character and reopens it — so a naive `'([^']*)'`
     * splits `Rip N' Burst` into two arguments and drops the rest of the line.
     *
     * @return list<string>
     */
    private function ledgerArguments(string $line): array
    {
        preg_match_all("/'((?:[^']|'\\\\'')*)'/", $line, $matches);

        return array_map(
            static fn (string $argument): string => str_replace("'\\''", "'", $argument),
            $matches[1],
        );
    }

    /**
     * Every spelling the league has told the site about, folded, pointing at
     * the blader it belongs to.
     *
     * This is the half of the alias problem that needs a person, and the
     * ledger is where that person's answer is already written down —
     * `app:alias add 'Obelix' 'Obelisk'` is somebody stating that those two
     * are one blader. A test that listed the same pairs again would be
     * transcribing an answer rather than checking one, and would have to be
     * retyped every time the admin import screen writes a new one.
     *
     * `app:alias reject` is deliberately not read. A rejection says two
     * spellings are two people, which is the default: an unlisted spelling
     * already reaches nobody.
     *
     * @return array<string, string> folded spelling => folded blader
     */
    private function aliasTable(): array
    {
        if (null !== $this->aliasTable) {
            return $this->aliasTable;
        }

        $normaliser = new AliasNormaliser();
        $aliases = [];

        foreach ($this->ledger() as $line) {
            if (!str_contains($line, 'app:alias add ')) {
                continue;
            }

            $arguments = $this->ledgerArguments($line);

            if (2 > count($arguments)) {
                continue;
            }

            $aliases[$normaliser->normalise($arguments[1])] = $normaliser->normalise($arguments[0]);
        }

        return $this->aliasTable = $aliases;
    }

    /**
     * Every blader the league has created, folded.
     *
     * @return list<string>
     */
    private function bladersDeclared(): array
    {
        $normaliser = new AliasNormaliser();
        $bladers = [];

        foreach ($this->ledger() as $line) {
            if (!str_contains($line, 'app:create-blader ')) {
                continue;
            }

            $arguments = $this->ledgerArguments($line);

            if ([] === $arguments) {
                continue;
            }

            $bladers[$normaliser->normalise($arguments[0])] = true;
        }

        return array_keys($bladers);
    }

    /**
     * The blader a spelling belongs to: itself, folded, unless the alias table
     * points it somewhere else.
     */
    private function bladerFor(?string $spelling): string
    {
        $folded = (new AliasNormaliser())->normalise((string) $spelling);

        return $this->aliasTable()[$folded] ?? $folded;
    }

    private function isTheSameBlader(?string $one, ?string $other): bool
    {
        return $this->bladerFor($one) === $this->bladerFor($other);
    }

    /**
     * Every tournament the league has imported, read out of the ledger — the
     * record of what was typed by hand, and so the only honest thing to check
     * a bracket against.
     *
     * @return list<array{file: string, slug: string, knockout: ?string, team: bool, unranked: bool}>
     */
    private function importedEvents(): array
    {
        $events = [];

        foreach ($this->ledger() as $line) {
            if (!str_contains($line, 'app:import-tournament')) {
                continue;
            }

            preg_match("/imports\/([^']+)\.txt'/", $line, $file);
            preg_match("/--challonge='([^']+)'/", $line, $url);
            preg_match("/--knockout='([^']+)'/", $line, $knockout);

            self::assertArrayHasKey(1, $file, sprintf('This import line names no placement file: %s', trim($line)));

            $events[] = [
                'file' => $file[1],
                'slug' => basename(rtrim($url[1] ?? '', '/')),
                'knockout' => $knockout[1] ?? null,
                'team' => str_contains($line, ' --team'),
                'unranked' => str_contains($line, ' --unranked'),
            ];
        }

        return $events;
    }

    /**
     * The 2v2 events, which are declared at import rather than detected —
     * `is_team` is false in every snapshot, the module store not carrying the
     * flag the fetch looks for, so `--team` in the ledger is the only record.
     *
     * @return list<string>
     */
    private function teamEventSlugs(): array
    {
        return array_values(array_map(
            static fn (array $event): string => $event['slug'],
            array_filter(
                $this->importedEvents(),
                static fn (array $event): bool => $event['team'] && '' !== $event['slug'],
            ),
        ));
    }
}
