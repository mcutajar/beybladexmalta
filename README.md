# Malta Beyblade League

[![Coverage](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/mcutajar/beybladexmalta/badges/coverage.json)](https://github.com/mcutajar/beybladexmalta/actions/workflows/ci.yaml)

The website that runs the Malta Beyblade Community League. It keeps the season
rankings, imports tournament results straight from Challonge brackets, and tracks
who has paid their seasonal registration — so the leaderboard players see is the
one the organisers actually agreed on.

## Who it is for

- **Players**, who mostly visit on a phone to check where they stand: the season
  leaderboard, their own results, past seasons and records.
- **Organisers**, who run the tournaments and keep the league honest: importing a
  finished bracket, registering payments, and merging a blader who turned up
  under two names.

## Why it is useful

A local league outgrows a spreadsheet quickly. Scores are capped at each player's
best 14 results, only registered players count towards a season, and the same
person enters brackets under three different spellings. This app does those rules
once, in one place:

- **Rankings that follow the league's rules** — best-14 scoring and payment gating
  are applied by the leaderboard itself, and a player's page shows which results
  the cap dropped.
- **Imports without retyping** — a Challonge bracket is read, previewed, matched
  onto known bladers and archived, rather than copied in by hand.
- **A full history of every admin action** — each one is recorded as a replayable
  command, so the league's data can be rebuilt from scratch at any time.
- **Built for the phone first**, because that is where almost everyone reads it.

## Getting started

Everything runs in Docker; PHP, Composer and Postgres are not needed on the host.
You need Docker with Compose, `make`, and `git`.

Run the dev stack from a **git worktree** rather than the checkout that serves
production — Compose names a project after its directory, so a stack started in
the production checkout replaces the live container:

```bash
git worktree add .claude/worktrees/<name> -b <branch>
cd .claude/worktrees/<name>
make setup
```

`make setup` starts the stack, builds the stylesheet and populates the database,
and is safe to re-run. If ports 80, 443 or 15432 are taken, set `HTTP_PORT`,
`HTTPS_PORT` and `DB_PORT` in a gitignored `.env.local`. The dev stack serves plain
HTTP, so open `http://localhost` (or your `HTTP_PORT`).

Day to day:

```bash
make check     # code style, static analysis and the test suite
make phpunit   # just the tests
make help      # every other target
```

## How it is built

- Symfony 8.1 on PHP 8.5, served by FrankenPHP
- PostgreSQL 16 through Doctrine ORM, with the leaderboard written as raw SQL
- Twig and Tailwind CSS, with a small component library in
  [`templates/components/`](templates/components/) and no JavaScript framework
- Docker Compose services: `php`, `database`, and an optional Cloudflare `tunnel`

[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) has the domain model, the services
and the known weak spots.

## Releases

Production runs a published, versioned image from
`ghcr.io/mcutajar/beybladexmalta`, never a build made on the host. Tagging a
release publishes one; deploying pulls it:

```bash
make release VERSION=1.1.0   # tag it; CI tests, builds and publishes the image
make deploy VERSION=1.1.0    # pull and start it on the production host
```

[`docs/RELEASING.md`](docs/RELEASING.md) has the full procedure, including
rollbacks and schema changes. [`CHANGELOG.md`](CHANGELOG.md) lists what each
release changed.

## Documentation

| Document | What it covers |
| --- | --- |
| [`AGENTS.md`](AGENTS.md) | Working conventions: where to run the stack, the rules that apply everywhere, and the traps worth knowing about |
| [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) | Architecture, domain model and hardening recommendations |
| [`docs/RELEASING.md`](docs/RELEASING.md) | Versioning, publishing, deploying and rolling back |
| [`docs/MOBILE.md`](docs/MOBILE.md) | The mobile-first rule and the measurements the layout was checked against |
| [`docs/DESIGN-PROPOSALS.md`](docs/DESIGN-PROPOSALS.md) | How a new page layout is proposed before it is built |
| [`.agents/skills/`](.agents/skills/) | Subsystem detail loaded on demand — the dev stack, tests, the design system, Challonge imports, releases |

## Admin access

The admin pages are gated by passphrases supplied to the container at run time
through `TOURNAMENTS_ADMIN_PASSPHRASE` and `PAYMENTS_ADMIN_PASSPHRASE`. Neither is
committed or baked into the image, and an unset passphrase refuses every request
rather than letting one through.

If you find a security problem, please don't describe it in a public issue.
[Report it privately](https://github.com/mcutajar/beybladexmalta/security/advisories/new)
instead — only the maintainer will see it.

## Getting help

Questions, bugs and suggestions all go to the
[issue tracker](https://github.com/mcutajar/beybladexmalta/issues). If you run a
league of your own and want to try this on it, an issue is the place to ask too.

## Maintainer

Maintained by [Matthew Cutajar](https://github.com/mcutajar), who is also the
person to ask about anything above. Contributions are welcome — open an issue
first for anything larger than a small fix, so the approach can be agreed before
the work is done.

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
