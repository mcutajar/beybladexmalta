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
| `make phpunit` | Run the test suite |
| `make phpunit ARGS="--filter FooTest"` | Run one test class |
| `make cs` | Check code style (no writes) |
| `make cs-fix` | Apply code style fixes |
| `make check` | Every quality gate — run before declaring work done |
| `make console ARGS="debug:router"` | Any `bin/console` command |
| `make composer ARGS="require --dev foo/bar"` | Any Composer command |
| `make shell` | Interactive shell in the container |

Every target fails fast with `make up` instructions if the stack is down, and
every target propagates the tool's real exit code and output.

Do not run `php`, `composer`, `vendor/bin/phpunit` or `docker run` directly.

## Container gotchas

- `docker run <image> php ...` is **not** a shortcut. The image's `ENTRYPOINT`
  (`frankenphp/docker-entrypoint.sh`) intercepts `php`, `frankenphp` and
  `bin/console`, then runs `composer install`, waits up to 60s for a database and
  applies migrations before your command. Use `docker compose exec` (what the
  Makefile does) — `exec` skips the entrypoint entirely.
- The stack bind-mounts the repository root at `/app`. A **git worktree is not
  mounted** by a stack started from the main checkout. Working in a worktree means
  either starting a separate stack from it (set `COMPOSE_PROJECT_NAME` and change
  the published ports to avoid clashing with the main one) or making the change in
  the main checkout.
- `vendor/` is written into the bind mount, so it lands on the host and the IDE can
  still index it. Keep it that way.

## Tests

- PHPUnit 13 with Zenstruck Foundry factories and stories (`src/Factory`, `src/Story`).
- Tests run against `bbx_malta_test` — a separate database from your real
  `bbx_malta` data, via `dbname_suffix` in `config/packages/doctrine.yaml`.
  `#[ResetDatabase]` drops and recreates it on each run.
- `.env.test` holds the admin passphrases the functional tests submit.
- `var/data/imports/` is **tracked by git** and holds real league data. Tests that
  write there must clean up after themselves in `tearDown()`.
- `SymfonyStyle` hard-wraps console output, so normalise whitespace before
  asserting on a message rather than matching a long raw string.

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
- `PlayerRepository::getLeagueLeaderboard()` is raw SQL with a CTE — it caps scoring
  at each player's best 14 results and applies payment gating. Change it with care;
  it is currently untested.
- `migrations/` is empty; the schema is not versioned yet.

`docs/ARCHITECTURE.md` has the fuller picture, including known weak spots.

## Before you call the work done

1. `make cs-fix`
2. `make check`
3. Report the actual result. If tests fail, say so and show the output.
