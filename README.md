# Malta Beyblade League

[![Coverage](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/mcutajar/beybladexmalta/badges/coverage.json)](https://github.com/mcutajar/beybladexmalta/actions/workflows/ci.yaml)

A Symfony 8.1 application for managing Malta Beyblade league rankings, tournament imports, and seasonal registration payments.

## Architecture summary

- Symfony 8.1 + PHP 8.5
- Doctrine ORM entities for players, seasons, tournaments, results and season registrations
- Custom SQL-based leaderboard aggregation in repository classes
- Admin import and registration workflows implemented via web forms and CLI commands
- FrankenPHP / Caddy-based Docker runtime for production-like deployments
- Tailwind CSS assets built through `symfonycasts/tailwind-bundle`, with design
  tokens and a Twig component library under `templates/components/`

## Runtime

The application runs inside Docker using the following services:

- `php` – FrankenPHP application container
- `database` – PostgreSQL 16
- `tunnel` – optional Cloudflare Tunnel sidecar

Startup commands:

- Production: `make deploy VERSION=1.1.0`
- Development: `make setup` (see [Local development](#local-development) for where
  to run it)

Both wrap `docker compose` with `--env-file .env --env-file .env.local` and the
right Compose file. Naming both env files is deliberate: a `--env-file` replaces
the `.env` Compose would otherwise read, so passing only `.env.local` blanks out
every variable the committed `.env` defines. Repeated flags layer, with the later
file winning.

## Releases

Production runs a published, versioned image — never a build made on the host.
A git tag is what publishes one:

```
make release VERSION=1.1.0   # from the production checkout: checks, tags, pushes
                             # CI then tests, builds and publishes the image
make deploy VERSION=1.1.0    # from the production checkout: pulls it, restarts
make rollback VERSION=1.0.0  # the same, pointed at an earlier version
make versions                # the releases, with the live one marked
```

Images are kept at `ghcr.io/mcutajar/beybladexmalta`, one tag per version, and
nothing prunes them — so any release can be started again, and a rollback is a
pull rather than a rebuild. Versions follow semantic versioning and are never
reused.

`make deploy` checks that the deploy actually landed: that the kernel boots, that
the compiled cache is newer than the code it shipped, and that the container is
running the version that was asked for. The first two have gone wrong before, and
neither takes the site down in a way that is obvious from the outside.

Secrets are not in the image. The published image is public and is built by CI
from a checkout with no `.env.local`, so `APP_SECRET`, `DATABASE_URL` and the
admin passphrases are passed into the container at run time from the production
host's env files; `make deploy` refuses to start when one of them is empty.

[`docs/RELEASING.md`](docs/RELEASING.md) has the whole procedure, including what
to bump and what a rollback does not undo.

## Local development

**Run the dev stack from a git worktree, not the checkout that runs production.**
Compose derives a project name from the directory, so a dev stack started in the
production checkout is not a second stack — it replaces the live container. `make
up`, `down` and `build` refuse when they find a production container, but the habit
is what keeps you out of trouble:

```
git worktree add .claude/worktrees/<name> -b <branch>
cd .claude/worktrees/<name>
make setup
```

A worktree gets its own Compose project, network and volumes automatically. Only
the published ports are shared, so if 80, 443 or 15432 are taken, set `HTTP_PORT`,
`HTTPS_PORT` and `DB_PORT` in a gitignored `.env.local`.

All tooling runs inside the container; PHP and Composer are not needed on the host.
The `Makefile` wraps `docker compose exec` for each tool, and `make help` lists
every target.

```
make setup     # start the stack, build the stylesheet, populate the database
make check     # code style, static analysis and tests
```

`make setup` is the whole fresh-clone sequence, and is safe to re-run: it seeds
only when the database has no schema yet. `make up`, `make tailwind`, `make
db-create` and `make seed` are available individually.

The Tailwind stylesheet is a build artifact and is not committed. Without it the
app fails with an AssetMapper error, so build it after cloning; the production
image builds its own during `docker build`.

## The database schema

The schema is not versioned through migrations — `migrations/` is empty
deliberately. Tables are created from the current entity mapping with
`doctrine:schema:create`, and the data is rebuilt by replaying `repeat.sh`, the
accumulated ledger of every admin action ever taken. Applying a schema change
therefore means taking the site down, dropping the database, recreating it and
replaying the ledger.

`make db-reset` runs exactly that sequence against the dev stack. It refuses to
run unless it is pointed at `compose.override.yaml`, so it cannot drop a
production database.

## Admin workflows

Admin endpoints currently use environment passphrases instead of a full Symfony security firewall:

- `TOURNAMENTS_ADMIN_PASSPHRASE` protects `/admin/import`
- `PAYMENTS_ADMIN_PASSPHRASE` protects `/admin/payments`

These values should be set in a local environment file, and the app should be hardened before production.

## Documentation

- `AGENTS.md` — working conventions: where to run the stack, the rules that
  apply everywhere, and the traps that are easy to lose an hour to.
- `.claude/skills/` (also exposed at `.agents/skills/` for Codex) — the detail
  that only matters inside one subsystem, loaded on demand: `dev-stack`,
  `writing-tests`, `design-system`, `design-proposal`, `challonge-import`,
  `release-and-deploy`.
- `docs/ARCHITECTURE.md` — architecture overview, domain model, and
  refactor/security recommendations.
- `docs/MOBILE.md` — the mobile-first rule and the measurements the current
  layout was verified against.

## Notes

- The test suite covers the payment and tournament import workflows; run it with `make phpunit`.
- Import and payment history are currently logged to `var/log/command_ledger.sh`.

## License

Copyright (C) 2026 Matthew Cutajar

This program is free software: you can redistribute it and/or modify it under
the terms of the GNU Affero General Public License as published by the Free
Software Foundation, either version 3 of the License, or (at your option) any
later version. See [LICENSE](LICENSE) for the full text.

In short: you are welcome to read this code, learn from it, and run your own
league on it. If you modify it and make it available to anyone over a network,
the AGPL requires you to offer them your modified source under the same terms.

If you want to use it on terms the AGPL does not grant — a closed-source
derivative, or a commercial service without publishing your changes — open an
issue and get in touch. Separate licensing can be arranged.
