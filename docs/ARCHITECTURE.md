# Malta Beyblade League Architecture

## Overview

This Symfony 8.1 application provides a public leaderboard and authenticated administration workflows for importing tournaments and registering seasonal payments. It is designed as a lightweight league ranking engine with manual admin entry, a small domain model, raw SQL leaderboard aggregations, and a FrankenPHP/Caddy container runtime.

## Core components

### Web UI

- `src/Controller/LeagueController.php`
  - Public leaderboard pages per season.
  - Player detail pages showing top 14 tournament contributions for a season.
  - Tournament details pages for individual result breakdowns.
  - Registration listing page showing paid season entries.
  - Versioned static proposal pages (`/`, `/v1`, `/v2`, `/v0`).

- `src/Controller/AdminTournamentImportController.php`
  - Admin form for importing a 10-player ranked tournament list.
  - Validates strict `YYYY-MM-DD` date format.
  - Uses `TOURNAMENTS_ADMIN_PASSPHRASE` for form-based admin gating.
  - Creates tournaments, players, and tournament results inside a transaction.
  - Writes an import replay command to `var/log/command_ledger.sh`.

- `src/Controller/LeagueRegistrationController.php`
  - Admin payment registration form.
  - Uses `PAYMENTS_ADMIN_PASSPHRASE` for form-based admin gating.
  - Auto-creates players when a payer is not already present.
  - Marks season registrations as paid and writes a ledger entry.

### CLI support

- `src/Command/ImportTournamentCommand.php`
  - Imports tournament results from a text or CSV file.
  - Accepts optional `--challonge`, `--season`, and `--knockout` options.
  - Prompts to select or create a season when missing.
  - Creates tournaments, players, and tournament results and logs the action.

- `src/Command/RegisterPlayerPaymentCommand.php`
  - Marks a player as paid for a season.
  - Supports both interactive and single-pass headless execution.
  - Auto-creates missing players when needed.
  - Writes a replay command to `var/log/command_ledger.sh`.

### Domain model

- `App\Entity\Player`
  - Represents a blader participant.
  - `name` is unique in the database.

- `App\Entity\Season`
  - Represents a competitive season.
  - Includes `requiresPayment` to gate leaderboard inclusion behind payment.

- `App\Entity\Tournament`
  - Represents a ranked event associated with a season.
  - Stores optional `challongeUrl` metadata.

- `App\Entity\TournamentResult`
  - Associates a player with a tournament finish.
  - Stores rank, `f1Points`, `bonusPoints`, and derived `totalPoints`.

- `App\Entity\SeasonRegistration`
  - Tracks whether a player has paid for a season.
  - Enforces unique player-season pairs.

### Data access and leaderboard logic

The app uses Doctrine ORM entities together with custom raw SQL in repository classes for aggregation.

- `App\Repository\PlayerRepository::getLeagueLeaderboard()`
  - Aggregates season leaderboards with a `WITH` CTE.
  - Uses `ROW_NUMBER()` to cap scoring at each player's best 14 tournament results.
  - Applies payment gating against season registration status.

- `App\Repository\PlayerRepository::getPlayerContributingTournaments()`
  - Returns the top 14 tournament contributions for a player within a season.

- `App\Repository\TournamentRepository::getTournamentStandings()`
  - Returns ordered placement results for a tournament.

- `App\Repository\SeasonRegistrationRepository::getAllSeasonalPayments()`
  - Returns all paid registrations grouped by season.

### Runtime and deployment

- Built as a FrankenPHP image in `Dockerfile`.
- Uses Caddy for HTTP serving.
- Web assets are managed with Tailwind via `symfonycasts/tailwind-bundle`.
- `compose.yaml` defines `php`, `database`, and `tunnel` services.
- PostgreSQL is the database backend.
- Log/ledger output is written into the mounted `var/log/` directory.

## Architectural concerns and hardening opportunities

### Authentication and access control

- No Symfony security firewall or user authentication is configured.
- Admin pages rely solely on a submitted passphrase from environment variables.
- This is not sufficient for production-grade admin protection.

Suggested hardening:
- Add Symfony Security bundle configuration.
- Protect `/admin/*` routes with a firewall and an admin role.
- Use proper authentication rather than form-only passphrases.
- Consider protecting the CLI and admin UI with separate credentials.

### Audit and ledger handling

- The app writes replayable shell commands to `var/log/command_ledger.sh`.
- This file is used as a human-readable ledger, not a formal audit log.
- It is written directly from both web and CLI flows.

Suggested hardening:
- Replace the shell-derived ledger with a dedicated audit table or log service.
- Record structured metadata: action type, time, actor, entities, and result.
- Treat `command_ledger.sh` as an audit convenience artifact rather than the authoritative event store.

### Duplication in import/payment logic

- Web and CLI imports duplicate parsing and player lookup semantics.
- Lookup behavior differs between contexts: CLI does exact-match name lookup, while web uses case-insensitive lookup.
- The same F1 point matrix is duplicated.

Suggested refactor:
- Extract shared import and payment services.
- Centralize player resolution and validation logic in repository helpers.
- Keep the F1 point matrix in one source of truth.

### Player name normalization and uniqueness

- `Player.name` is unique, but case insensitivity is not enforced at the database layer.
- The application currently uses both exact and case-insensitive name matching.
- This can lead to duplicate players if names differ only by casing.

Suggested refactor:
- Enforce canonical player identifiers.
- Normalize names before persistence, or use a case-insensitive DB type such as PostgreSQL `citext`.
- Provide a single repository method for case-insensitive lookups.

### Derived state management

- `TournamentResult::totalPoints` is a stored derived field updated only when specific setters are called.
- If `f1Points` or `bonusPoints` are changed via alternate code paths, `totalPoints` may become stale.

Suggested refactor:
- Compute `totalPoints` on demand.
- Or use Doctrine lifecycle callbacks to ensure the derived field is always recalculated.
- Alternatively, derive totals in SQL and avoid storing redundant data.

### Validation consistency

- The web import flow validates exactly 10 players; the CLI import accepts any number of non-empty rows.
- The F1 matrix is hard-coded in each import implementation.
- There is no shared domain-level validation for tournament size or rank structure.

Suggested refactor:
- Centralize import validation rules in a shared service.
- Enforce consistent tournament input expectations across web and CLI.
- Add validation for player names and bonus point limits.

### Lack of automated tests

- There is no `tests/` directory or test coverage in the repository.
- The critical leaderboard, import, and payment workflows are currently untested.

Suggested improvement:
- Add PHPUnit or Symfony test coverage for key workflows.
- Start with unit tests for repository SQL and service behaviors.
- Add functional tests for admin page access and form handling.

## Recommended next steps

1. Document the architecture and security concerns in `README.md` and `docs/ARCHITECTURE.md`.
2. Introduce Symfony Security and protect all admin routes.
3. Refactor import and payment handling into reusable service classes.
4. Normalize player names and enforce uniqueness at the database layer.
5. Replace shell ledger writes with a robust audit log.
6. Add a minimal test suite for exportable domain behaviors.
