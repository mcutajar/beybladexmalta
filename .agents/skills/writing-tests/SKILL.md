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
  `#[ResetDatabase]` drops and recreates it on each run.
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
  Xdebug, which the dev image already ships and `compose.override.yaml` already
  puts in coverage mode, so nothing needs installing — but an `XDEBUG_MODE` in
  `.env.local` without `coverage` in it produces empty reports and a PHPUnit
  warning rather than a failure. It writes three views of the same run to the
  gitignored `var/coverage/`: `cobertura.xml` (what CI turns into the per-file
  table), `html/` (which lines are missed) and the text summary in the
  terminal.
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
