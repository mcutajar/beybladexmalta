# Malta Beyblade League

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

- Production: `docker compose --env-file .env.local -f compose.yaml up -d --build`
- Development: `docker compose --env-file .env.local -f compose.override.yaml up -d --build`

## Local development

All tooling runs inside the container; PHP and Composer are not needed on the host.
The `Makefile` wraps `docker compose exec` for each tool, and `make help` lists
every target.

```
make up        # start the dev stack
make tailwind  # build the stylesheet (once, on a fresh clone)
make check     # code style and tests
```

The Tailwind stylesheet is a build artifact and is not committed. Without it the
app fails with an AssetMapper error, so build it after cloning; the production
image builds its own during `docker build`.

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
