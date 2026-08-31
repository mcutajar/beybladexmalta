# AGENTS.md

Malta Beyblade League — a Symfony 8.1 / PHP 8.5 app for league rankings, tournament
imports and seasonal registration payments. Postgres 16, Doctrine ORM, Twig +
Tailwind, served by FrankenPHP.

## Skills

Detail that only matters inside one subsystem lives in `.claude/skills/`, loaded
on demand. The rules below are the ones that apply everywhere, or that you have
to know *before* you would think to go looking.

| Skill | Covers |
| --- | --- |
| `dev-stack` | Bringing the stack up, ports and `.env.local`, the browser preview, seeding and resetting the database |
| `writing-tests` | Base test cases, factories, fixtures, artifact cleanup, coverage |
| `design-system` | Tokens, the page shell, Twig components and their traps, mobile sizing |
| `design-proposal` | The format for proposing a layout before it is built |
| `challonge-import` | Reading, snapshotting, importing and archiving brackets; aliases, teams, the import preview |
| `release-and-deploy` | Cutting a release, publishing the image, deploying, rolling back |

## The one rule: everything runs in the container

There is no PHP, Composer or Postgres on the host, and none should be installed.
The dev container defined in `compose.override.yaml` is the only supported runtime.
Use the `Makefile` — it wraps `docker compose exec` for every tool:

| Command | What it does |
| --- | --- |
| `make setup` | Bootstrap a fresh clone end to end (do this first) |
| `make up` | Start the dev stack |
| `make tailwind` | Rebuild the stylesheet (needed once on a fresh clone) |
| `make db-reset` | Drop the database, recreate the schema, replay `repeat.sh` |
| `make seed` | Replay `repeat.sh` on its own |
| `make phpunit` | Run the test suite |
| `make phpunit ARGS="--filter FooTest"` | Run one test class |
| `make coverage` | Run the suite and write the coverage reports to `var/coverage` |
| `make cs` | Check code style (no writes) |
| `make cs-fix` | Apply code style fixes |
| `make phpstan` | Run static analysis (level 6) |
| `make check` | Every quality gate — run before declaring work done |
| `make changelog` | Rewrite CHANGELOG.md from the commits (add `VERSION=` to preview) |
| `make release VERSION=1.1.0` | Tag a release; CI builds and publishes the image |
| `make versions` | List the releases, marking the one production is running |
| `make console ARGS="debug:router"` | Any `bin/console` command |
| `make composer ARGS="require --dev foo/bar"` | Any Composer command |
| `make shell` | Interactive shell in the container |

Every target fails fast with `make up` instructions if the stack is down, and
every target propagates the tool's real exit code and output.

First run on a fresh clone is a single `make setup`. It starts the stack, waits for
the container's healthcheck, builds the stylesheet and populates the database. All
three matter: without the built stylesheet every page fails with an AssetMapper
error, and without the database every leaderboard route 500s on a table that does
not exist. `make phpunit` builds the stylesheet on its own when it is missing.

`make setup` is safe to re-run. It only creates and seeds when the schema is absent,
so it will never discard a database you have been working with — rebuilding one on
purpose is `make db-reset`.

Do not run `php`, `composer`, `vendor/bin/phpunit` or `docker run` directly.

The `gh` CLI is installed and authenticated, so issues and PRs can be driven from
the shell. Homebrew's `/opt/homebrew/bin` is not on a non-interactive shell's PATH,
so `gh` is symlinked into `/usr/local/bin`, which is. Recreate that on a fresh
machine with `sudo ln -s /opt/homebrew/bin/gh /usr/local/bin/gh`.

## Where to run the dev stack

**In a git worktree, not the main checkout.** The main checkout is where production
runs, and Compose derives a project name from the directory — so a dev stack started
there is not a second stack, it is production being replaced.

```bash
git worktree add .claude/worktrees/<name> -b <branch>
cd .claude/worktrees/<name>
# ports 80/443/15432 are probably taken; see the `dev-stack` skill
make setup
```

The worktree gets its own Compose project (`<name>-php-1`), its own network and its
own volumes, automatically. Nothing needs `COMPOSE_PROJECT_NAME`.

| | Where | Command |
| --- | --- | --- |
| Local development | a worktree | `make setup`, `make up`, `make check`, … |
| Deploying | the main checkout | `make deploy VERSION=1.1.0` |
| Releasing | the main checkout | `make release VERSION=1.1.0` |

Both directions are guarded: the dev stack targets refuse to run where a production
container is present, and `make deploy` names `-f compose.yaml` so it cannot pick up
the dev override. Neither guard is a substitute for knowing which directory you are
in — `docker ps` shows the project prefix on every container.

Running the *production* compose file from a worktree is harmless, incidentally: it
would build a separate project named after the worktree and leave the live site
alone. Only the main checkout reaches production.

The `dev-stack` skill has the rest: the container gotchas, the `--env-file`
layering trap, the port collisions, and the browser preview. Two things are worth
knowing without opening it — **`docker run <image> php ...` is not a shortcut**
(the image's entrypoint intercepts it; use `docker compose exec`, which is what
the Makefile does), and the dev stack serves **plain HTTP**, so preview it at
`http://localhost` or `http://localhost:$HTTP_PORT` rather than over HTTPS.

## Releasing and deploying

**A git tag publishes an image; a deploy pulls one.** `make release VERSION=x.y.z`
tags, CI builds and pushes the image, `make deploy VERSION=x.y.z` pulls it.
The `release-and-deploy` skill and `docs/RELEASING.md` have the procedure and the
reasoning. Four rules bind anything that touches this, whether or not you are
cutting a release:

- **`compose.yaml` has no `build:` key, and must not gain one.** With one, a bare
  `docker compose -f compose.yaml up -d` builds the working copy and stamps a
  release version on it, and production serves something no release produced.
- **Never deploy with a bare `docker compose`.** In this checkout it also reads
  `compose.override.yaml`, which builds the dev target and bind-mounts the working
  copy over `/app`. Every production command names its file: `-f compose.yaml`.
- **Nothing may be mounted over `/app/var` in production.** It hides the warmed
  cache the image ships, and the site goes on serving an older build while every
  release looks like a no-op. Only `var/log` and `var/data/imports` are mounted.
- **Version numbers are never reused.** A bad release is followed by the next
  number, not a retagged one.

**Secrets are supplied at run time, not baked into the image**, and an unset admin
passphrase is an open door rather than a locked one — `hash_equals('', '')` is
`true`. Any new passphrase-gated flow goes through `AdminPassphraseVerifier`,
which refuses everything when the configured passphrase is empty, rather than
calling `hash_equals()` directly.

**A commit subject is published.** `cliff.toml` renders the release notes and
`CHANGELOG.md` straight from the commits, so a subject is worth the same care as
the code; the body is not rendered and is still where the reasoning belongs.
Pull requests are rebase-merged, not squashed, for the same reason.

## Tests

`make phpunit` runs the suite; `make phpunit ARGS="--filter FooTest"` runs one
class. The `writing-tests` skill has the full picture. The core of it:

- PHPUnit 13 with Zenstruck Foundry factories and stories, under `tests/` and
  namespaced `App\Tests\`. They do not belong in `src/`.
- A new test extends one of the base cases in `tests/Support` — `AdminPageTestCase`,
  `ConsoleTestCase`, `InteractsWithTheLedger`, `LeagueAssertions` — rather than
  `WebTestCase` or `KernelTestCase` directly. Reach for a named assertion before
  inlining factory criteria; **only what varies belongs in a test body**.
- Tests run against a separate `bbx_malta_test` database, so they cannot touch
  your real data. They do write to the real `var/`: artifact cleanup is
  centralised through `artifactPaths()`, and a test that writes somewhere new
  overrides that method rather than writing its own `tearDown()`.
- **No test reaches Challonge.** `config/services_test.yaml` hands
  `ChallongeFetcher` a `MockHttpClient` answering from `tests/Fixtures/challonge/`.
  A test needing a new bracket shape adds a fixture there rather than a URL.
- `var/data/imports/` and `var/data/challonge/` are **tracked by git** and hold
  real league data. Nothing else under `var/` belongs in git.

## Mobile-first is not a preference

**Most people reach this site on a phone.** The narrow layout is the design; the
desktop one is the enhancement. This governs every UI change here.

The unprefixed Tailwind utility describes the *phone*, and `sm:` / `md:` / `lg:`
only ever grow it — a breakpoint is never a patch for something authored at
desktop width. Columns start at one, type scales up rather than down, and
horizontal room at 375px is the scarcest resource on the site.

**Check it before calling UI work done**: a 375px viewport, the leaderboard and
whichever page you touched. The `design-system` skill has the specifics and
`docs/MOBILE.md` the measurements the current layout was verified against.

## Design system

There is no JavaScript framework here. The system is design tokens in
`assets/styles/app.css`, one page shell in `templates/base.html.twig` that every
route extends, components in `templates/components/` used as `<twig:Badge>`, and
exactly one script. `/_styleguide` renders every component in every variant and
`PageRendersTest` requests it. The `design-system` skill covers all of it,
including the Twig component traps. Three rules that bite from outside it:

- **The site ships exactly one script and it may not be load-bearing.** Anything
  it does must already work without it. `ExpandableTable` is the whole of it.
- **Tailwind class strings belong in a component's template, never in its PHP
  class.** Tailwind scans `templates/` and not `src/`, so a class named in PHP is
  never compiled — it only appears to work while some template happens to use the
  same utility.
- **Templates use the token names.** `slate-800` should not reappear in one.

## Proposing a layout

Anything that needs a layout decision — a new page, a rebuild of an existing one
— goes through a **design proposal** before it is built, and proposals here have
a fixed shape: a companion component library started from `/_styleguide`, two or
three options per page that differ in purpose rather than styling, every mockup
at 375px and full width, and the choice deferred to the ticket that builds it.
The `design-proposal` skill and `docs/DESIGN-PROPOSALS.md` have the format.

**Never read a proposal as documentation of the site.** `/_styleguide` is the
factual component library; a proposal's is mostly drawings of blocks that do not
exist yet.

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
- **Nothing reads a Challonge bracket without the smoke check**, **a snapshot is
  a transcription rather than an interpretation**, and **a Challonge display name
  is never turned into a blader**. Those three carry a large body of rules about
  imports, aliases, teams and the archive — read the `challonge-import` skill
  before touching any of it.
- **`LedgerService` builds every command string**, and hands them back unappended
  when asked. Nothing composes its own approximation of a `repeat.sh` line.

## Things that will surprise you

- `symfony/validator` is **not** installed. `$form->isValid()` is effectively always
  true after submission, so validation is hand-rolled in the controller.
- An untouched text control comes back from Symfony as **null**, not `''`, and
  the form DTOs type those properties as `string` — so an empty title or an
  empty placement list used to 500 in the property accessor before any
  hand-rolled validation ran. Every non-nullable field carries
  `'empty_data' => ''`. A `ChoiceType` with a placeholder is the exception: its
  "nothing chosen" really is null, however hard `empty_data` is leaned on, so
  `BracketConfirmData::$knockoutWinner` is nullable.
- Admin routes are gated by an environment passphrase submitted in the form, not by
  a Symfony security firewall. There is no user entity and no login.
- `PlayerRepository::getLeagueLeaderboard()` is raw SQL with a CTE — it caps scoring
  at each player's best 14 results and applies payment gating. Change it with care;
  it is currently untested.
- `migrations/` is empty **on purpose**, and should stay that way. The schema is not
  versioned incrementally; it is rebuilt from the current entity mapping. A schema
  change is deployed by taking the site down, dropping the database, running
  `doctrine:schema:create` and replaying `repeat.sh` to restore the data. `make
  db-reset` is that same procedure for the dev stack. Do not add an initial migration
  to "fix" the empty directory: the entrypoint runs `doctrine:migrations:migrate` on
  every boot under `set -e`, so a migration that tries to `CREATE TABLE` over the
  live schema would kill the production container on startup.
- Replaying `repeat.sh` is only safe against an **empty** schema —
  `app:import-tournament` has no guard and a second replay silently doubles every
  tournament it holds, which is why `make seed` is paired with a drop. See
  `dev-stack`.
- PHPStan runs at **level 6** with the Symfony, Doctrine and PHPUnit extensions,
  and there is **no baseline** — the analysis is clean, so keep it that way rather
  than adding one. It resolves against a compiled container that goes stale when
  services change; `dev-stack` has the fix.
- **`orphanRemoval` schedules the delete the moment a child leaves the
  collection**, not at flush: `PersistentCollection::removeElement()` calls
  `scheduleOrphanRemoval()` there and then, and the only thing that cancels it is
  `PersistentCollection::add()` — which a freshly constructed entity's plain
  `ArrayCollection` has no hook for. So moving a child from a loaded parent to a
  brand-new one writes the `UPDATE` and then the `DELETE` in one flush, and the
  code that did it reports success for a row that is gone.
  `TournamentStage::$matches` cascades a remove for exactly this reason;
  `$participants` keeps orphan removal because an entrant never moves between
  stages.
- Because the Doctrine extension reads the real mapping, **entity property types
  must match the column nullability**. A `NOT NULL` column needs a non-nullable
  property, so new entity fields should not default to `?T ... = null` out of habit.

`docs/ARCHITECTURE.md` has the fuller picture, including known weak spots.
`docs/RELEASING.md` covers versioning, publishing and rollback.

## Before you call the work done

1. `make cs-fix`
2. `make check` (code style, PHPStan, then the test suite)
3. Report the actual result. If tests fail, say so and show the output.
