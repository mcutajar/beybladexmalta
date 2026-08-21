# Malta Beyblade League

[![Coverage](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/mcutajar/beybladexmalta/badges/coverage.json)](https://github.com/mcutajar/beybladexmalta/actions/workflows/ci.yaml)

A Symfony 8.1 application for managing Malta Beyblade league rankings, tournament imports, and seasonal registration payments.

## Architecture summary

- Symfony 8.1 + PHP 8.5
- Doctrine ORM entities for players, seasons, tournaments, results and season registrations
- Custom SQL-based leaderboard aggregation in repository classes
- Admin import and registration workflows implemented via web forms and CLI commands
- FrankenPHP / Caddy-based Docker runtime for production-like deployments
- Tailwind CSS assets built through `symfonycasts/tailwind-bundle`

## Runtime

The application runs inside Docker using the following services:

- `php` – FrankenPHP application container
- `database` – PostgreSQL 16
- `tunnel` – optional Cloudflare Tunnel sidecar

Startup commands:

- Production: `docker compose --env-file .env --env-file .env.local -f compose.yaml up -d --build`
- Development: `docker compose --env-file .env --env-file .env.local -f compose.override.yaml up -d --build`

Both env files are named on purpose: a `--env-file` replaces the `.env` Compose
would otherwise read, so passing only `.env.local` blanks out every variable the
committed `.env` defines. Repeated flags layer, with the later file winning. The
`Makefile` does the same thing for every `make` target.

## Local development

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

For a deeper architecture overview, domain model summary, and refactor/security recommendations, see `docs/ARCHITECTURE.md`.

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
