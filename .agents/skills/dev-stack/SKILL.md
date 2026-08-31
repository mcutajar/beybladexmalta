---
name: dev-stack
description: Running the Docker dev stack and its browser preview — starting a stack in a worktree, port collisions and .env.local, the compose --env-file trap, the HTTPS redirect trap, stale previews, seeding and resetting the database, and reading doctrine:schema:validate output. Use when bringing a stack up, when a container is unhealthy or a make target fails, when the preview will not load or shows the wrong app, or when reseeding or rebuilding the database.
---

# Running the dev stack

`AGENTS.md` has the Makefile table and the one rule (everything runs in the
container). This is what goes wrong and why.

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

## Seeding, resetting and reading schema output

The schema is not versioned by migrations — it is rebuilt from the current
entity mapping, and `migrations/` is empty on purpose (`AGENTS.md` has the rule
and why adding one would kill the production container on boot). Two things
follow from that:

- Nothing creates the schema in a **freshly started stack**, so
  `doctrine:schema:validate` reports "The database schema is not in sync with
  the current mapping file" and `doctrine:schema:update --dump-sql` prints
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

`make setup` is safe to re-run: it only creates and seeds when the schema is
absent, so it will never discard a database you have been working with.
Rebuilding one on purpose is `make db-reset`.

## PHPStan's compiled container goes stale

`phpstan.dist.neon` points the Symfony extension at the compiled dev container
(`make phpstan` warms it first) and the Doctrine extension at
`tests/object-manager.php`. That warm-up is an **order-only
prerequisite**, so it only runs when the compiled container is missing. Add,
rename or rewire a service and the cached XML goes stale, after which
phpstan-symfony silently resolves against the old container. Run
`make console ARGS="cache:clear --env=dev"` after container changes.
