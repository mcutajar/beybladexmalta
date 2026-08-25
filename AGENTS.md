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
  hands Compose `--env-file .env --env-file .env.local` — and every `make` target
  then works unchanged. Point `.claude/launch.json` at the same `HTTP_PORT`; see
  "Previewing in a browser" below.
- That pair of flags is deliberate, and **a single `--env-file .env.local` is a
  trap**. Compose *replaces* the `.env` it would otherwise read with the file you
  name rather than layering on top of it, so a ports-only `.env.local` blanks out
  everything the committed `.env` defines. `compose.override.yaml` interpolates
  `DATABASE_URL: ${DATABASE_URL}`, which is only set in `.env`, so the container
  starts with an empty-but-present `DATABASE_URL`; Symfony's Dotenv then sees it as
  already set and never falls back to the `.env` value. The entrypoint's
  `dbal:run-sql` dies with "could not find driver", the healthcheck never passes,
  and `make up` leaves an unhealthy stack. Repeated `--env-file` flags *do* layer,
  later files winning, which is why the Makefile passes both. Driving Compose by
  hand needs the same pair.
- **A production stack may be running on the same host**, started from the main
  checkout via `compose.yaml` (plus the `production`-profile Cloudflare tunnel). A
  *worktree* stack cannot collide with it, because Compose keys a project on the
  directory name and a worktree has its own. **The main checkout does not get that
  protection**: there, the dev stack and production are the same project, so
  `make up` recreates production's container with dev config rather than starting
  one beside it — and the tunnel keeps pointing at it. `make down`, `docker compose
  down` or a prune run from that directory takes the live site down outright.
  `make up`, `down` and `build` now refuse when a production container is present
  (see `not-production` in the Makefile), but check `docker ps` before anything
  destructive and keep every command scoped the way the Makefile does.
- `vendor/` is written into the bind mount, so it lands on the host and the IDE can
  still index it. Keep it that way.
- On the **first** `make up` of a fresh checkout the entrypoint is running its own
  `composer install` into that bind mount. Running `make composer` (or anything
  else) before it finishes corrupts `vendor/` — the two writers fight and the
  container dies mid-extraction, leaving packages half-installed. Wait for the
  container to report healthy (`make ps`) before invoking any other target. If it
  does get into that state, `rm -rf vendor`, restore `composer.json`/`composer.lock`,
  and let a single `composer install` finish on its own.

## Previewing in a browser

The dev stack serves **plain HTTP** — `http://localhost` by default, or
`http://localhost:$HTTP_PORT` when `.env.local` overrides the port.
`compose.override.yaml` sets `SERVER_NAME=:80` to make that the default, and
production is unaffected because it runs from `compose.yaml`.

That default exists because the alternative bites hard. With a hostname in
`SERVER_NAME`, Caddy serves HTTPS from an internal CA that nothing trusts
(`frankenphp/Caddyfile` sets `skip_install_trust`) and 308s port 80 to a
**port-less** `https://localhost` — so a worktree published on 8082 redirects to
whatever holds 443, which on a machine running several stacks is a different
app entirely. Browsers cache a 308 permanently, so the bad redirect outlives the
fix: the symptom is `ERR_CERT_AUTHORITY_INVALID` on a URL you never typed, for
one path while its siblings work. Shake it off with a throwaway query string
(`?x=1`) or by clearing the browser's cache.

`.claude/launch.json` registers the stack with the browser preview, which will
not open a localhost origin it does not know about. Two things to know:

- **Its `port` and `url` must match the port this checkout publishes.** A stale
  value does not fail loudly — it previews a different worktree's app.
- A localhost entry must be a bare origin. Paths and queries are rejected, so
  open the page you want by navigating after the preview attaches.

It attaches to a running stack rather than starting one, so `make up` first. If
navigation starts failing with "denied or failed" after a container restart, the
preview session has gone stale — attach again.

## Where to run the dev stack

**In a git worktree, not the main checkout.** The main checkout is where production
runs, and Compose derives a project name from the directory — so a dev stack started
there is not a second stack, it is production being replaced.

```bash
git worktree add .claude/worktrees/<name> -b <branch>
cd .claude/worktrees/<name>
# ports 80/443/15432 are probably taken; see the .env.local note above
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

## Releasing and deploying

A deploy is no longer a build. **A git tag publishes an image; a deploy pulls
one.** `docs/RELEASING.md` is the full procedure — the short version:

```bash
make release VERSION=1.1.0   # from the main checkout: tags and pushes
                             # CI then tests, builds and pushes the image
make deploy VERSION=1.1.0    # from the main checkout: pulls it and restarts
make rollback VERSION=1.0.0  # the same thing, pointed at an older version
```

`make versions` lists the releases and marks the live one; `make prod-version`
prints what production is running, read from the container's
`org.opencontainers.image.version` label.

Things that are load-bearing here:

- **`compose.yaml` has no `build:` key, and must not gain one.** With one, a bare
  `docker compose -f compose.yaml up -d` builds the working copy and stamps a
  release version on it, and production serves something no release produced.
  Without one, that command fails on a missing manifest. The default tag is
  `:none` for the same reason — nothing publishes it, so a command that forgot
  to name a version fails rather than picking one.
- **Never deploy with a bare `docker compose`.** In this checkout it also reads
  `compose.override.yaml`, which builds the `frankenphp_dev` target and
  bind-mounts the working copy over `/app`. Every production command names its
  file: `-f compose.yaml`. `make deploy` does this for you.
- **Version numbers are never reused.** A bad release is followed by the next
  number, not a retagged one.
- **`make release` is cut from the main checkout, not a worktree** -- the one
  exception to working in a worktree. Git will not check out `main` in a
  worktree while the main checkout holds it, and a release has to be cut from
  main. It starts no dev stack, so it is safe to run beside a production
  container; it does not re-run the suite locally, because CI has already
  tested the commit it is cutting from and the release workflow tests the tag
  again before publishing.
- **The changelog is written before the tag, not after it.** `make release`
  regenerates `CHANGELOG.md` from the commits, commits it as
  `chore(release): vX.Y.Z` and pushes to main, and only then tags -- so the
  tag's tree contains the entry describing it. This cannot move into the
  release workflow: main's ruleset requires the `PHPUnit` check on any push,
  only repository admins bypass it, and a push made with `GITHUB_TOKEN` does
  not trigger the workflows that would report it. The cost is that the tagged
  commit is one past the one `release-gate` read CI's verdict for.
- **The image is `linux/arm64` only**, because production is Docker Desktop on
  Apple Silicon. The release job runs on an arm64 runner so that is a native
  build rather than a QEMU one.
- **An image built by hand reports version `0.0.0-dev`.** The label comes from a
  build argument only the release workflow passes, which is what lets
  `verify-deploy` tell a release apart from a local build.

### The release notes are the commits

`cliff.toml` turns the conventional commit types into groups, and both the
GitHub Release body and `CHANGELOG.md` are rendered from it -- the workflow
renders the newest section, `make release` renders the file. Nothing is written
by hand and `--generate-notes` (pull request titles) is gone.

Three consequences worth carrying:

- **A commit subject is published.** It ends up in a release note and in a file
  people read, so it is worth the same care as the code. The body is not
  rendered, and is still where the reasoning belongs.
- **Nothing is dropped.** A commit that does not parse lands in an `Other`
  group rather than vanishing, on purpose.
- **Pull requests are rebase-merged.** Squashing is still enabled but would
  degrade the changelog to a single pull request title with a blank body, which
  is what this replaced. `docs/RELEASING.md` records the reasoning.

git-cliff is not a PHP tool, so it is not in the dev container; `make changelog`
runs it from a pinned image, which keeps the host clean the same way the
container rule does.

### Secrets are supplied at run time, not baked in

They used to be baked in: the build ran on the production host, `.env.local` was
in the build context, and `composer dump-env prod` compiled it into
`.env.local.php` inside a layer. A published image is public and CI has no
`.env.local`, so that route is closed at both ends.

`.dockerignore` excludes `.env.local`, `compose.yaml` passes `APP_SECRET`,
`DATABASE_URL`, `DEFAULT_URI` and both admin passphrases into the container from
the host's env files, and Symfony's Dotenv leaves an already-set variable alone
so those win over the image's committed defaults. `make deploy` runs
`deploy-preflight` first and refuses to start when one is empty, naming the
variable and never printing a value.

**An unset admin passphrase is an open door, not a locked one.**
`hash_equals('', '')` is `true`, so a container that never received
`PAYMENTS_ADMIN_PASSPHRASE` would accept an empty form field. That became
reachable the moment passphrases stopped being baked in, so
`AdminPassphraseVerifier` refuses everything when the configured passphrase is
empty and logs it as critical. Any new passphrase-gated flow goes through it
rather than calling `hash_equals()` directly.

### Why `make deploy` verifies rather than just restarting

Three things can go wrong without the site going down, and the first two have:

1. **The image ships code whose dependencies were never installed.** The kernel
   cannot boot, and the site 502s -- but only once something forces it to boot
   fresh. `verify-deploy` runs `bin/console about`, which fails loudly instead.
2. **A compiled cache outlives the code it was compiled from.** Symfony never
   revalidates the container, the routes or Twig in production, so the site goes
   on serving an older build and every release looks like a no-op. This happened
   for a month: `compose.yaml` mounted a volume over `/app/var`, hiding the
   warmed cache the image ships (the Dockerfile copies `/app/var` in as its own
   layer) behind one compiled in July. `verify-deploy` fails if any file under
   `src/`, `config/` or `templates/` is newer than `var/cache/prod`.
3. **The container that came up is not the version that was asked for.**
   `verify-deploy` compares the running container's version label against the
   version the deploy named.

Nothing may be mounted over `/app/var` in production. Only the two directories
the app writes to at runtime are mounted, and both are named: `LedgerService`
appends to `var/log`, `ImportFileWriter` writes `var/data/imports`.

If a deploy fails verification, `make prod-logs` is the next stop.

## Tests

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
  gitignored `var/coverage/`: `cobertura.xml` (what CI turns into the pull
  request table), `html/` (which lines are missed) and the text summary in the
  terminal.
- CI runs the same target on every pull request, posts the per-file table as a
  single comment that is edited in place on each push, repeats it in the job
  summary and uploads the HTML report as the `coverage-html` artifact. Nothing
  is sent to a third-party service and no secret is involved. Coverage is
  reported only — it never fails the build.
  - The comment needs a writable token, which a run from a fork or from
    Dependabot is never given, so it is skipped there. The summary and the
    artifact still appear.
  - Files with no executable lines — interfaces, enums, empty exception classes
    — show as 0%. They are counted as 0/0, so they do not move the total.
  - The README badge reads a shields.io endpoint JSON on the `badges` orphan
    branch, which a push to `main` rewrites. That branch holds that one file and
    nothing else; never merge it into anything.
- Artifact cleanup is centralised: the base cases delete everything `artifactPaths()`
  lists, before and after each test. A test that writes somewhere new overrides that
  method instead of writing its own `tearDown()`.

## Mobile-first is not a preference

**Most people reach this site on a phone.** The narrow layout is the design; the
desktop one is the enhancement. This governs every UI change here.

In practice that means the unprefixed Tailwind utility describes the *phone*, and
`sm:` / `md:` / `lg:` only ever grow it — a breakpoint is never a patch for
something authored at desktop width. Concretely:

- Horizontal room at 375px is the scarcest resource on the site. Padding added to
  a card is width taken from a table. The leaderboard is six columns on a phone
  and is the first thing to break.
- Type scales up, not down: a heading is sized for the phone and given `md:` to
  grow. `text-4xl md:text-6xl`, never `text-6xl` with a `sm:` shrink.
- Columns start at one. `grid-cols-1 sm:grid-cols-2` — never the reverse.
- A column that is dropped on small screens uses `hidden sm:table-cell`, so the
  phone gets the shorter table and the desktop the fuller one.
- Do not set `maximum-scale` or `user-scalable=no` on the viewport meta. Pinch
  zoom is how people read a dense table on a phone.

**Check it before calling UI work done.** A 375px viewport, the leaderboard and
whichever page you touched. `docs/MOBILE.md` records the measurements the current
layout was verified against.

## Design system

There is no JavaScript framework here, and a design system does not need one.
It is three things:

1. **Tokens**, in `assets/styles/app.css`. A Tailwind v4 `@theme` block names every
   colour, radius and glow after the job it does — `bg-surface`, `text-ink-muted`,
   `rounded-card`, `shadow-brand-glow` — aliasing Tailwind's own scale underneath,
   so the current look is exact and a repaint is a change in one file. Templates
   use the token names; `slate-800` should not reappear in one.
2. **`templates/base.html.twig`**, the one page shell. Every route extends it and
   overrides `title`, `column`, `accent_bar`, `body_classes` or `html_classes` as
   needed. No template declares `<!DOCTYPE>` any more.
3. **Components**, in `templates/components/`, used as `<twig:Badge tone="flame">`
   through `symfony/ux-twig-component`. `make console ARGS="debug:twig-component"`
   lists them.

A component gets a PHP class in `src/Twig/Components/` when it has a variant
vocabulary worth typing or something to derive — `Badge`, `Card`, `Button`,
`Alert`, `RankMedal`, `BonusPoints`, `PointsMatrix`, `Flashes`. It is an
anonymous template with `{% props %}` when it is only markup — `PageHeader`,
`DataTable`, `Field`, `LinkCard`, `FeatureTile`, `Disclosure`, `EmptyState`,
`SectionHeading`, `BackLink`.

**Tailwind class strings belong in the component's template, never in its PHP
class.** Tailwind scans `templates/` and not `src/`, so a class named in PHP is
never compiled — it only appears to work while some template happens to use the
same utility. The PHP class names the variant; the template maps the variant to
classes. Form field styling used to break this rule and is now `.field`, applied
by the form theme in `templates/form/theme.html.twig`, so the form types in
`src/Form/` carry no presentation at all.

**Component props are plain strings, not HTML.** `title="Points &amp; standings"`
renders a literal `&amp;`, because Twig escapes the value again on output. Write
the character. Entities are only correct inside a component's *content*, which is
markup: `<twig:Button>Verify &amp; process</twig:Button>` is right.

`/_styleguide` renders every component in every variant. It is registered in dev
and test only, through `config/routes/styleguide.yaml`, and has no controller
because it shows no data. `PageRendersTest` requests it, so a component that
breaks fails the suite rather than a page.

## Proposing a layout

Anything that needs a layout decision — a new page, a rebuild of an existing one
— goes through a **design proposal** before it is built, and proposals here have
a fixed shape. `docs/DESIGN-PROPOSALS.md` is the format; the short version:

- **There are two component libraries and only one is real.** `/_styleguide` is
  the factual one — it renders what is actually in `templates/components/`, and
  `PageRendersTest` requests it. A proposal's component library is the *proposed*
  one: mostly drawings of blocks that do not exist yet. Never read a proposal as
  documentation of the site.
- **The proposed library is a companion document to the proposal**, not a section
  inside it, so it can be reviewed on its own and diffed against
  `/_styleguide` — that diff is the build list. The proposal's section `01` links
  to it and lists the block names, so the proposal still reads alone.
- **Both are proposal artifacts and both stop when the proposal does.** When a
  ticket builds a block it goes into `templates/components/` and `/_styleguide`,
  and from then on the styleguide describes it. Do not maintain the proposal's
  library afterwards.
- **Start the library from `/_styleguide`.** Every entry is marked *in the
  styleguide*, *extension* or *new*; an existing component keeps its real name
  (`Card`, not `PANEL`); a block assembled from existing ones says **built from**
  rather than claiming to be new. Only genuinely new blocks get a
  `SCREAMING-KEBAB` name, and that name becomes the file when it is built.
- **Tables are the usual offender.** `DataTable` owns the scroll shell, the
  `dense` and `bleed` props and the `.data-table` cell rhythm. Six
  different-looking tables in a proposal are six sets of columns inside one
  component, not six components.
- **Two or three options per page**, each a different idea about what the page is
  *for* rather than a restyle, each tagged with the blocks it uses, each with at
  least one honest cost.
- **Every mockup rendered at 375px and at full width from the same markup**,
  using container queries rather than viewport media queries — so the narrow view
  is the same component at phone size and not a second drawing of it.
- **Choices are communicated as a letter per page plus component swaps**:
  `3A but swap H2H-TABLE for H2H-BARS`.
- **The choice is made when the ticket starts, not when the proposal is written.**
  The ticket says so explicitly.

Proposals are published as private artifacts, so a ticket must carry everything a
contributor needs in its own body. The link is a convenience for whoever owns it.

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
- **Nothing reads a Challonge bracket without the smoke check.** It lives inside
  `ChallongeFetcher`, ahead of the parse and the write, so an import cannot begin
  on a page that has changed shape — and a path added later inherits the gate
  rather than having to remember it. `app:challonge-smoke` runs the same check on
  its own, against a live bracket, a page saved with `--file`, or on a cron.
  A consequence worth knowing: a bracket whose ranking stage renders no readable
  standings can no longer be captured at all. That is deliberate — there is no
  finishing order to import out of one — and `app:challonge-smoke` is how such a
  page is looked at instead.
- **A Challonge snapshot is a transcription, not an interpretation.**
  `var/data/challonge/<slug>.json` keeps every fact the bracket stated — every
  match with its per-game scorelines, the entrants, the standings tables column
  for column — and none of what only the embed needs. What it must never gain is
  a conclusion: no column renamed into our vocabulary, no display name resolved
  to one of our players. Those change, and a tracked file cannot. Turning a
  snapshot into domain objects happens when it is read, where a mistake costs a
  re-parse rather than a re-fetch of a bracket that may be gone.
- **A Challonge display name is never turned into a blader.** Two hundred and
  seven spellings across the captured brackets belong to about seventy-six
  people, and `AliasNormaliser` only folds that to a hundred and twenty-nine —
  case, punctuation and `(invitation pending)`. The rest is `PlayerAlias`, a
  stored table somebody curates, because `Obelix` and `Obelisk` are two letters
  apart and are two people. `AliasResolver` returns an unrecognised name as a
  question with suggestions attached, and no caller may take a suggestion and
  act on it; `AliasService` is the only thing that writes, it refuses a spelling
  that folds onto a blader's own name, and it never creates a blader. Two rows
  for one person is a merge, not an alias.
- **That last rule guards only the alias side, on purpose.** Bladers also arrive
  by being invented from a placement list, which `app:import-tournament` still
  does, so a blader created later can shadow an alias filed before they existed.
  `AliasResolver` therefore treats a spelling that reaches two people — two
  blader rows, or a blader and an alias pointing elsewhere — as unresolvable
  rather than picking a side, because picking would split somebody's career
  across two rows silently. Closing it at the point of creation is #54's job;
  until then the collision is meant to be loud.
- **The table was not typed, it was read out of the imports.** Every event
  already imported is a labelled example — rank *n* of its captured bracket is
  line *n* of the placement list somebody typed at the time — so
  `app:bootstrap-aliases` derives the pairs, prints the lot, and writes only on
  `--force`. Fifteen rows came out of the sixteen non-team events and they are
  in `repeat.sh` like every other admin action. It writes nothing two events
  disagree about and creates nobody.
- **A team event teaches the alias pass nothing, and that is the phantom rule.**
  Its entrants are teams, so a name there belongs to two bladers rather than
  one, and the lists it was imported from name one blader per team slot —
  padded, where the roster was never known, with `JG1`, `JG2` and the literal
  `-`, `--` and `---`. Those five are rows in `players` and none of them is a
  person; `melhina` is the same fact with no placeholder at all, a team that
  finished eleventh and was never imported. Nothing is to be learned from any of
  them, nothing merged into them, and nothing resolved onto them. #67 puts an
  unclaimed team on record and takes the five rows back out.
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
- `migrations/` is empty **on purpose**, and should stay that way. The schema is not
  versioned incrementally; it is rebuilt from the current entity mapping. A schema
  change is deployed by taking the site down, dropping the database, running
  `doctrine:schema:create` and replaying `repeat.sh` to restore the data. `make
  db-reset` is that same procedure for the dev stack. Do not add an initial migration
  to "fix" the empty directory: the entrypoint runs `doctrine:migrations:migrate` on
  every boot under `set -e`, so a migration that tries to `CREATE TABLE` over the
  live schema would kill the production container on startup.
- A consequence of the above: in a **freshly started stack** nothing creates the
  schema, so `doctrine:schema:validate` reports "The database schema is not in sync
  with the current mapping file" and `doctrine:schema:update --dump-sql` prints
  `CREATE TABLE` for every table. That is an empty database, not drift you
  introduced. To tell the two apart, check whether the dump says `CREATE` (empty) or
  `ALTER` (real drift). `make setup` or `make db-reset` fills it in.
- Replaying `repeat.sh` is only safe against an **empty** schema. `app:create-season`
  reports an existing slug and stops, and `app:register-payment` returns `AlreadyPaid`,
  but `app:import-tournament` has no such guard — it inserts a fresh tournament and a
  full set of results every time. A second replay silently doubles all of them, which
  is why `make seed` is paired with a drop rather than run on its own.
- A replay also appends its own copy of every command to `var/log/command_ledger.sh`,
  so a ledger that already held lines ends up holding them twice. In dev that is
  cosmetic — `repeat.sh` is the record, and the ledger is gitignored.
- The test suite **deletes** `var/log/command_ledger.sh`. `artifactPaths()` lists it,
  and the base cases clear every artifact before and after each test, against the real
  project directory rather than a sandbox. Running `make phpunit` therefore discards
  whatever the last seed or admin action wrote there.
- Inside a component's content, **`this` and the component's own props are
  rebound to that component**. `{% for tone in tones %}<twig:Badge tone="{{ tone }}">{{ tone }}</twig:Badge>`
  prints the badge's tone enum, not the loop's string, and `this.rows` inside a
  nested `<twig:DataTable>` resolves against the table. Resolve what you need
  before opening the child, and name loop variables away from the child's props.
- A component's root element **cannot be another component with `attributes`
  forwarded into it** — `<twig:Card {{ attributes }}>` is a parse error — and
  nesting one defines `content` twice unless the outer component's own content is
  captured into a variable first. `templates/components/EmptyState.html.twig`
  shows both workarounds.
- Renaming or adding a component sometimes needs a **container restart**, not just
  `make console ARGS="cache:clear --env=dev"`: FrankenPHP runs in worker mode and
  holds compiled Twig templates in memory, so a stale error will keep pointing at
  a line number that no longer exists.
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
- Challonge's human-facing pages return **403** to anything that is not a browser,
  and so does `/<slug>/standings`. Only `challonge.com/<slug>/module` answers a
  plain client, and it carries the whole tournament in a
  `_initialStoreState['TournamentStore']` assignment. Send a User-Agent that names
  the site — an anonymous client is bounced — and keep `show_standings=1` on the
  URL, because without it a Swiss bracket renders no standings table at all and
  nothing fails until something tries to read one.
- `challonge.com/<slug>` *does* resolve a bracket that lives on a subdomain, by
  301, but the redirect drops the query string. `ChallongeUrl` therefore keeps the
  subdomain rather than letting the client follow the hop.
- The group stage and the final stage of the same bracket use **disjoint id
  spaces**. A blader who plays both appears under two unrelated ids with only
  their display name in common, so a snapshot lists participants per stage and
  never merges them.
- The **third-place playoff is not in `matches_by_round`**. It hangs off the store
  as `third_place_match`, and again as `consolation_matches`. Miss it and every
  bracket with a cut is one match short; merge it in unflagged and it looks like
  the final, which is how the knockout winner is identified.
- A standings row does not reliably carry the participant's name: a blader who
  linked their Challonge account is rendered as **that account instead**. Rows are
  joined to participants through the match ids in their match-history cell.

`docs/ARCHITECTURE.md` has the fuller picture, including known weak spots.
`docs/RELEASING.md` covers versioning, publishing and rollback.

## Before you call the work done

1. `make cs-fix`
2. `make check` (code style, PHPStan, then the test suite)
3. Report the actual result. If tests fail, say so and show the output.
