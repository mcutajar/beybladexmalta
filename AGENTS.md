# AGENTS.md

Malta Beyblade League — a Symfony 8.1 / PHP 8.5 app for league rankings, tournament
imports and seasonal registration payments. Postgres 16, Doctrine ORM, Twig +
Tailwind, served by FrankenPHP.

## The one rule: everything runs in the container

There is no PHP, Composer or Postgres on the host, and none should be installed.
The dev container defined in `compose.override.yaml` is the only supported runtime.
Use the `Makefile` — it wraps `docker compose exec` for every tool:

| Command | What it does |
| --- | --- |
| `make up` | Start the dev stack (do this first) |
| `make tailwind` | Rebuild the stylesheet (needed once on a fresh clone) |
| `make phpunit` | Run the test suite |
| `make phpunit ARGS="--filter FooTest"` | Run one test class |
| `make cs` | Check code style (no writes) |
| `make cs-fix` | Apply code style fixes |
| `make phpstan` | Run static analysis (level 6) |
| `make check` | Every quality gate — run before declaring work done |
| `make console ARGS="debug:router"` | Any `bin/console` command |
| `make composer ARGS="require --dev foo/bar"` | Any Composer command |
| `make shell` | Interactive shell in the container |

Every target fails fast with `make up` instructions if the stack is down, and
every target propagates the tool's real exit code and output.

First run on a fresh clone is `make up` then `make tailwind`. Without the built
stylesheet every page fails with an AssetMapper error, so `make phpunit` builds it
automatically when it is missing.

Do not run `php`, `composer`, `vendor/bin/phpunit` or `docker run` directly.

The `gh` CLI is installed and authenticated, so issues and PRs can be driven from
the shell. Homebrew's `/opt/homebrew/bin` is not on a non-interactive shell's PATH,
so `gh` is symlinked into `/usr/local/bin`, which is. Recreate that on a fresh
machine with `sudo ln -s /opt/homebrew/bin/gh /usr/local/bin/gh`.

## Container gotchas

- `docker run <image> php ...` is **not** a shortcut. The image's `ENTRYPOINT`
  (`frankenphp/docker-entrypoint.sh`) intercepts `php`, `frankenphp` and
  `bin/console`, then runs `composer install`, waits up to 60s for a database and
  applies migrations before your command. Use `docker compose exec` (what the
  Makefile does) — `exec` skips the entrypoint entirely.
- The stack bind-mounts the repository root at `/app`. A **git worktree is not
  mounted** by a stack started from the main checkout. Working in a worktree means
  either starting a separate stack from it or making the change in the main checkout.
- Starting that separate stack needs **less ceremony than it looks**. Compose derives
  the project name from the directory, so a worktree already gets its own namespace
  (`<worktree-dir>-php-1`, its own network and volumes) — you do not need to set
  `COMPOSE_PROJECT_NAME`. Only the **published ports** are shared, and only against
  another *dev* stack: `compose.override.yaml` publishes 80, 443 and 15432 by
  default. Check with `docker ps` first; if something already holds them, put
  `HTTP_PORT`, `HTTPS_PORT` and `DB_PORT` in a gitignored `.env.local` — the Makefile
  hands it to Compose with `--env-file` — and every `make` target then works
  unchanged.
- **A production stack may be running on the same host**, started from the main
  checkout via `compose.yaml` (plus the `production`-profile Cloudflare tunnel). It
  is a *different* Compose project, so a worktree stack cannot collide with it — but
  `make down`, `docker compose down` or a prune run from the wrong directory will
  take the live site down. Check `docker ps` before anything destructive, and keep
  every command scoped with `-f compose.override.yaml` the way the Makefile does.
- `vendor/` is written into the bind mount, so it lands on the host and the IDE can
  still index it. Keep it that way.
- On the **first** `make up` of a fresh checkout the entrypoint is running its own
  `composer install` into that bind mount. Running `make composer` (or anything
  else) before it finishes corrupts `vendor/` — the two writers fight and the
  container dies mid-extraction, leaving packages half-installed. Wait for the
  container to report healthy (`make ps`) before invoking any other target. If it
  does get into that state, `rm -rf vendor`, restore `composer.json`/`composer.lock`,
  and let a single `composer install` finish on its own.

## Tests

- PHPUnit 13 with Zenstruck Foundry factories and stories (`src/Factory`, `src/Story`).
- Tests run against `bbx_malta_test` — a separate database from your real
  `bbx_malta` data, via `dbname_suffix` in `config/packages/doctrine.yaml`.
  `#[ResetDatabase]` drops and recreates it on each run.
- `.env.test` holds the admin passphrases the functional tests submit.
- `var/data/imports/` is **tracked by git** and holds real league data. Tests that
  write there must clean up after themselves in `tearDown()`.
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
- Artifact cleanup is centralised: the base cases delete everything `artifactPaths()`
  lists, before and after each test. A test that writes somewhere new overrides that
  method instead of writing its own `tearDown()`.

## Conventions

- `declare(strict_types=1);` in every PHP file — enforced by php-cs-fixer.
- Style is `@Symfony` via `.php-cs-fixer.dist.php`. Run `make cs-fix`, never
  hand-format.
- **Controllers and commands stay thin.** They gather input, delegate to a service,
  and translate the outcome into a flash message or console output. Domain rules
  live in `src/Service`.
- Services report outcomes with a **result enum** (`RegisterSeasonPaymentResult`,
  `TournamentImportResult`) that callers `match` on. Exceptions are for failures,
  not for expected outcomes.
- Forms use a **DTO + `AbstractType`** pair (`src/Dto` + `src/Form`), never
  `createFormBuilder()` inline in a controller.
- Repositories own `persist()` via `save()` methods; services commit through
  `FlusherInterface`. Do not inject `EntityManagerInterface` into a service.
- Every admin action appends a replayable command to `var/log/command_ledger.sh`
  through `LedgerService`, which owns the command-string construction.
  `repeat.sh` is the accumulated result and doubles as a recovery script.
- **Ledger writes go inside the flush transaction**, via
  `FlusherInterface::flushThen()`. The ledger must never gain a line for a change
  the database rejected, and a failed ledger write must roll the change back.
  Preserve this when adding any new ledger-writing flow.
- Compare admin passphrases with `hash_equals()`.

## Things that will surprise you

- `symfony/validator` is **not** installed. `$form->isValid()` is effectively always
  true after submission, so validation is hand-rolled in the controller.
- Admin routes are gated by an environment passphrase submitted in the form, not by
  a Symfony security firewall. There is no user entity and no login.
- `KernelTestCase` ships a **static** `runCommand()` in Symfony 8.1. Declaring an
  instance helper of that name is a fatal error at class-load time ("Cannot make
  static method ... non static"), which reads as a broken autoloader rather than a
  name clash. The project's wrapper is `ConsoleTestCase::executeCommand()`.
- `PlayerRepository::getLeagueLeaderboard()` is raw SQL with a CTE — it caps scoring
  at each player's best 14 results and applies payment gating. Change it with care;
  it is currently untested.
- `migrations/` is empty; the schema is not versioned yet. A consequence worth
  knowing: in a **freshly started stack** nothing ever creates the schema, so
  `doctrine:schema:validate` reports "The database schema is not in sync with the
  current mapping file" and `doctrine:schema:update --dump-sql` prints `CREATE TABLE`
  for every table. That is an empty database, not drift you introduced. To tell the
  two apart, check whether the dump says `CREATE` (empty) or `ALTER` (real drift).
- PHPStan runs at **level 6** with the Symfony, Doctrine and PHPUnit extensions,
  and there is **no baseline** — the analysis is clean, so keep it that way
  rather than adding one. `phpstan.dist.neon` points the Symfony extension at the
  compiled dev container (`make phpstan` warms it first) and the Doctrine
  extension at `tests/object-manager.php`.
- That warm-up is an **order-only prerequisite**, so it only runs when the compiled
  container is missing — exactly like the Tailwind rule above it. Add, rename or
  rewire a service and the cached XML goes stale, after which phpstan-symfony
  silently resolves against the old container. Run
  `make console ARGS="cache:clear --env=dev"` after container changes.
- Because the Doctrine extension reads the real mapping, **entity property types
  must match the column nullability**. A `NOT NULL` column needs a non-nullable
  property, so new entity fields should not default to `?T ... = null` out of habit.

`docs/ARCHITECTURE.md` has the fuller picture, including known weak spots.

## Before you call the work done

1. `make cs-fix`
2. `make check` (code style, PHPStan, then the test suite)
3. Report the actual result. If tests fail, say so and show the output.
