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

## Admin workflows

Admin endpoints currently use environment passphrases instead of a full Symfony security firewall:

- `TOURNAMENTS_ADMIN_PASSPHRASE` protects `/admin/import`
- `PAYMENTS_ADMIN_PASSPHRASE` protects `/admin/payments`

These values should be set in a local environment file, and the app should be hardened before production.

## Documentation

For a deeper architecture overview, domain model summary, and refactor/security recommendations, see `docs/ARCHITECTURE.md`.

## Notes

- No automated tests are present in this repository.
- Import and payment history are currently logged to `var/log/command_ledger.sh`.
