---
name: writing-tests
description: How this project's test suite is put together — the base test cases and their helpers, Foundry factories and stories, the test database, the Challonge fixtures, artifact cleanup, and coverage. Use when writing, moving or debugging a test, when a test writes files or reads the ledger, when a test passes but should not, or when running make coverage or changing CI's coverage reporting.
---

# Writing tests

- PHPUnit 13 with Zenstruck Foundry factories and stories (`tests/Factory`,
  `tests/Story`, namespaced `App\Tests\`). They live under `tests/` rather than
  `src/` on purpose: `zenstruck/foundry` is a dev dependency, so anything
  extending it belongs in `autoload-dev`. In `src/` they were shipped to the
  production image without the package they extend, and counted against coverage
  as if they were application code.
- Tests run against `bbx_malta_test` — a separate database from your real
  `bbx_malta` data, via `dbname_suffix` in `config/packages/doctrine.yaml`.
- **`#[ResetDatabase]` rolls a test back; it no longer rebuilds the schema.**
  `dama/doctrine-test-bundle` wraps every test in a transaction and rolls it
  back at the end, and Foundry defers to it when it is installed. Without it
  Foundry's own resetter runs `doctrine:schema:drop --full-database` and
  `doctrine:schema:update` *before every single test* — 531 of them — which was
  the whole of the suite's runtime (58s against 9s) and bloated the test
  database's catalog until later runs took minutes. Two things follow from the
  transaction:
  - **A test cannot see another connection's data, and cannot commit.** Nothing
    here opens a second connection, and the nested transactions
    `FlusherInterface::flushThen()` opens become savepoints, so the ledger's
    rollback behaviour is unchanged. A test that genuinely needs committed data
    would have to opt out.
  - **Schema changes made inside a test are not undone.** Nothing does this.
- `.env.test` holds the admin passphrases the functional tests submit.
- `var/data/imports/` and `var/data/challonge/` are **tracked by git** and hold real
  league data — the placement lists, and the captured Challonge brackets. Tests that
  write there must clean up after themselves in `tearDown()`.
- **No test reaches Challonge.** `config/services_test.yaml` hands `ChallongeFetcher`
  a `MockHttpClient` built by `tests/Support/FakeChallonge`, which answers from
  `tests/Fixtures/challonge/` and, like the real site, only renders standings when
  `show_standings=1` was sent. A test needing a new bracket shape adds a fixture
  there rather than a URL.
- Nothing else under `var/` belongs in git. `var/tailwind/` holds the built
  stylesheet and a ~112 MB downloaded Tailwind binary; both are generated.
- `SymfonyStyle` hard-wraps console output, so normalise whitespace before
  asserting on a message rather than matching a long raw string.
  `ConsoleTestCase::assertCommandSaid()` already does this.
- Shared test plumbing lives in `tests/Support`, and a new test should extend one of
  the base cases rather than `WebTestCase` or `KernelTestCase` directly:
  - `AdminPageTestCase` — booting the browser, submitting a page's real form (so the
    CSRF token is genuine), and `assertFlashSays()`.
  - `ConsoleTestCase` — `executeCommand()` plus the output and exit-code assertions.
    Subclasses name their command in `commandName()`.
  - `InteractsWithTheLedger` — asserts what `var/log/command_ledger.sh` holds by
    rebuilding the exact replayable command; `blockLedgerWrites()` puts a directory
    in its place to force a write failure.
  - `LeagueAssertions` — domain assertions such as `assertPlayerHasPaid()`,
    `assertResultAtRank()` and `assertPlacementsScoredInOrder()`.
- **Only what varies belongs in a test body.** The correct passphrase, the payment
  season and the happy-path form values are helper defaults, so a test that names a
  passphrase is visibly a test *about* authentication. Reach for a named assertion
  before inlining factory criteria.
- `make coverage` measures how much of `src/` the suite exercises. The driver is
  **PCOV**, which the dev image ships alongside Xdebug and which
  `20-app.dev.ini` leaves disabled, so only that target pays for it. It writes
  three views of the same run to the gitignored `var/coverage/`:
  `cobertura.xml` (what CI turns into the per-file table), `html/` (which lines
  are missed) and the text summary in the terminal.
  - **Xdebug is the step debugger here, not the coverage driver.** It can
    measure coverage, and used to, but it instruments every opcode: the same
    suite takes 45s under Xdebug and 18s under PCOV, for line rates within
    0.2pp. `XDEBUG_MODE` is `off` for the whole stack, which is what lets
    PHPUnit pick PCOV up — setting it to anything with `coverage` in it in
    `.env.local` puts Xdebug back in charge and quietly undoes that, and costs
    the plain suite 35s against 9s for a report it was never asked for.
  - **Xdebug is still there, it is just not armed.** Its mode is a container
    environment variable, so `.env.local` would mean recreating the container.
    Pass it to a single command instead — the Makefile forwards it:

    ```bash
    make phpunit XDEBUG_MODE=debug ARGS="--filter ImportTournamentCommandTest"
    ```

    `debug` is the step debugger (`20-app.dev.ini` already points it at the
    host), `develop` the richer errors and `var_dump`. Both extensions stay
    loaded and both are inert by default, so having the choice costs nothing:
    with neither armed the suite is the same 9s.
  - **PCOV measures lines and nothing else.** There is no branch or path
    coverage, which is why CI hides that column. Getting it back means
    `XDEBUG_MODE=coverage make coverage`, which hands the reports back to
    Xdebug — slower again, and rarely what you want. The two drivers do not
    conflict: with both armed PHPUnit takes PCOV for lines and Xdebug for
    branch and path.
  - **PCOV flatters an untaken branch of a multi-line conditional.** The two
    drivers agree exactly on which lines are *executable* — zero differences
    across all 171 files — but PCOV attributes coverage by the line of each
    executed opcode, so the final arm of a `match` or the true branch of a
    ternary can be marked hit when it never ran. Xdebug tracks statements and
    tells them apart. Today that is nine lines in three files out of 5,739, and
    Xdebug is right in every one of them. It is not worth changing driver over,
    but do not trust a per-line PCOV report on a `match`-heavy file — check it
    with `XDEBUG_MODE=coverage make coverage` before concluding a branch is
    covered.
- CI runs the same target on every pull request, writes the per-file table to
  the job summary and uploads the HTML report as the `coverage-html` artifact.
  Nothing is sent to a third-party service and no secret is involved. Coverage
  is reported only — it never fails the build.
  - **The table is not posted as a pull request comment.** It was, and one row
    per file landing in the review thread on every push drowned the
    conversation. The job summary carries the same numbers, so the CI job needs
    no `pull-requests: write` token and a run from a fork or from Dependabot
    reports exactly as much as one from a branch.
  - Files with no executable lines — interfaces, enums, empty exception classes
    — show as 0%. They are counted as 0/0, so they do not move the total.
  - The README badge reads a shields.io endpoint JSON on the `badges` orphan
    branch, which a push to `main` rewrites. That branch holds that one file and
    nothing else; never merge it into anything.
- Artifact cleanup is centralised: the base cases delete everything `artifactPaths()`
  lists, before and after each test. A test that writes somewhere new overrides that
  method instead of writing its own `tearDown()`.

## Traps

- `KernelTestCase` ships a **static** `runCommand()` in Symfony 8.1. Declaring an
  instance helper of that name is a fatal error at class-load time ("Cannot make
  static method ... non static"), which reads as a broken autoloader rather than a
  name clash. The project's wrapper is `ConsoleTestCase::executeCommand()`.
- The test suite **deletes** `var/log/command_ledger.sh`. `artifactPaths()` lists it,
  and the base cases clear every artifact before and after each test, against the real
  project directory rather than a sandbox. Running `make phpunit` therefore discards
  whatever the last seed or admin action wrote there.
- **A test that reads an entity after a flush may be reading the identity map,
  not the database.** Doctrine hands back the objects it already has, and a
  fetch-join does not necessarily repopulate a collection it has already
  initialised — so a row deleted at the last flush can go on answering every
  question the test puts to it. Where what matters is what *survived*, assert
  against SQL: `ChallongeArchiveServiceTest::rowIds()` does, and it is the only
  reason the `orphanRemoval` bug `AGENTS.md` records was visible at all.

## Related

`dev-stack` covers seeding and resetting the database the suite runs against.
