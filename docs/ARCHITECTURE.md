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
  - Uses `TOURNAMENTS_ADMIN_PASSPHRASE` for form-based admin gating.
  - Enforces the exactly-ten-placements rule, then delegates to `TournamentImportService`.

- `src/Controller/LeagueRegistrationController.php`
  - Admin payment registration form.
  - Uses `PAYMENTS_ADMIN_PASSPHRASE` for form-based admin gating.
  - Auto-creates players when a payer is not already present.
  - Marks season registrations as paid and writes a ledger entry.

### Presentation layer

- `assets/styles/app.css`
  - Tailwind v4 `@theme` block naming every colour, radius and glow after the job
    it does (`surface`, `ink-muted`, `brand`, `radius-card`) rather than the swatch.
  - `.data-table` and `.field` component rules, so table cells and form controls
    keep one rhythm across pages.

- `templates/base.html.twig`
  - The single page shell: head, accent bar, centred column, footer. Every route
    extends it; no template declares its own document.

- `templates/components/` and `src/Twig/Components/`
  - Twig components (`symfony/ux-twig-component`) for the recurring pieces:
    badges, cards, buttons, alerts, the results table shell, form fields, the
    landing pages' link cards and points matrix.
  - `/_styleguide` renders all of them in every variant, in dev and test only.

- `templates/form/theme.html.twig`
  - Puts `.field` on every widget so form types hold no presentation.

### CLI support

- `src/Command/ImportTournamentCommand.php`
  - Imports tournament results from a text or CSV file.
  - Accepts optional `--challonge`, `--season`, and `--knockout` options.
  - Prompts to select or create a season when missing.
  - Parses the file, then delegates to `TournamentImportService`.

- `src/Command/RegisterPlayerPaymentCommand.php`
  - Marks a player as paid for a season.
  - Supports both interactive and single-pass headless execution.
  - Auto-creates missing players when needed.
  - Writes a replay command to `var/log/command_ledger.sh`.

- `src/Command/CreateSeasonCommand.php`
  - Creates a competitive season, prompting for anything not passed as an argument.
  - Reports an existing slug rather than creating a duplicate.
  - Writes its replay command inside the flush transaction, like the other two flows.

### Application services

Both the web and CLI entry points are thin: they gather input, then hand it to a
service that owns the domain rules.

- `App\Service\TournamentImportService`
  - The single source of truth for the F1 points matrix and the knockout bonus.
  - Resolves or creates players case-insensitively, builds the tournament and its results.
  - Accepts only strict `YYYY-MM-DD` dates, for web and CLI alike.
  - Writes the recovery artifacts inside the flush transaction, so the tournament
    and its ledger entry either both survive or neither does.

- `App\Service\PlacementListParser`
  - Parses an ordered placement list into `App\Dto\TournamentPlacement` objects.
  - Shared by the CLI file reader and the web textarea, so both accept `Name` and `Name,bonus` rows.

- `App\Service\ImportFileWriter`
  - Materialises a web-submitted placement list into `var/data/imports/`, so the
    ledger replay command always has a source file to point at.

- `App\Service\PlayerRegistrationService`
  - Marks a player as paid for a season, auto-creating the player when needed.

- `App\Service\LedgerService`
  - Owns the construction of every replay command written to `var/log/command_ledger.sh`.

- `App\Service\FlusherInterface::flushThen()`
  - Flushes, then runs the ledger write inside the same transaction, rolling back
    if it fails. This is what keeps the ledger and the database in step.

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

### Duplication in import/payment logic (addressed)

Both flows now share a service layer, which resolved the following:

- Parsing, player lookup, and ledger command construction are no longer duplicated.
- Player lookup is case-insensitive in both contexts, via `PlayerRepositoryInterface::findByName()`.
- The F1 point matrix and knockout bonus live only in `TournamentImportService`.

Ledger consistency:
- Every flow that writes the ledger -- payment, tournament import and season
  creation -- goes through `FlusherInterface::flushThen()`, which flushes first
  and commits only once the entry is on disk.
- A failed ledger write rolls the database change back; a failed database write
  never reaches the ledger. `repeat.sh` therefore cannot gain a line for a payment,
  a tournament or a season that was not stored.
- The one remaining window is a failure of the commit itself, after the ledger line
  is written. Closing that would require a two-phase write or moving the ledger into
  the database, which is the direction the audit note below points.

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

- Date parsing is now strict `YYYY-MM-DD` in both flows.
- The web import still requires exactly 10 placements while the CLI accepts any
  non-empty list, since replaying `repeat.sh` depends on that leniency.
- `symfony/validator` is not installed, so form validation is hand-rolled in the controller.

Suggested refactor:
- Install the Validator component and express the rules as constraints on the form DTOs.
- Decide whether the exactly-ten rule belongs to the domain rather than the web form.
- Add validation for player names and bonus point limits.

### Test coverage

- PHPUnit and Zenstruck Foundry cover the payment and tournament import workflows,
  from both the web form and the console command.
- The leaderboard SQL in `PlayerRepository` remains untested.

Suggested improvement:
- Add coverage for `getLeagueLeaderboard()`, especially the best-14 cap and payment gating.
- Add functional tests for the public season, player, and tournament pages.

## Recommended next steps

1. Document the architecture and security concerns in `README.md` and `docs/ARCHITECTURE.md`.
2. Introduce Symfony Security and protect all admin routes.
3. Normalize player names and enforce uniqueness at the database layer.
4. Replace shell ledger writes with a robust audit log.
5. Add a minimal test suite for exportable domain behaviors.
