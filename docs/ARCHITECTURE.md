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
  - `--team` declares a 2v2 event, whose file is a roster rather than a placement
    list. It cannot be combined with `--knockout`: a team event awards no bonus.
  - Writes a replay command to `var/log/command_ledger.sh`.

- `src/Command/CreateSeasonCommand.php`
  - Creates a competitive season, prompting for anything not passed as an argument.
  - Reports an existing slug rather than creating a duplicate.
  - Writes its replay command inside the flush transaction, like the other two flows.

- `src/Command/FetchChallongeCommand.php`
  - Captures a Challonge bracket as `var/data/challonge/<slug>.json`.
  - Accepts every URL shape Challonge hands out, including the subdomain and
    invite-link forms.
  - Writes **no** ledger line: a replay must never depend on Challonge still
    serving the bracket. The snapshot is what later steps will point at.

- `src/Command/ChallongeSmokeCommand.php`
  - `app:challonge-smoke [url] [--file=PATH]` — the smoke check on its own,
    against a live bracket or a page already on disk. Writes nothing either way.
  - Named no URL it reads one known finished bracket, which is what the
    `Challonge smoke check` workflow runs on a Wednesday-morning cron. A failure
    there raises an issue, so a change to `/module` surfaces days before an event
    rather than during one.
  - Prints the whole checklist, passes included: the expectations either side of
    a failure are what say how much of the page is still the page we knew.

- `src/Command/AliasCommand.php`
  - `app:alias add|list|remove` — the stored table that says which Challonge
    spelling belongs to which blader.
  - `add` refuses a blader nobody has heard of, and prints who the name might
    have been instead. An alias never creates a player.
  - `add` is replay-safe: a line already on file reports itself and writes
    nothing, so `repeat.sh` can be replayed whole.
  - Writes its replay command inside the flush transaction, naming the blader
    under the name the database holds.

- `src/Command/BootstrapAliasesCommand.php`
  - `app:bootstrap-aliases [--force]` — reads the alias table out of the events
    already imported, and prints it before it writes anything. The default run
    touches nothing.
  - Every event already imported is a labelled example: rank *n* of its captured
    bracket is line *n* of the placement list typed at the time. Fifteen aliases
    fall out of the sixteen non-team events, all of them in `repeat.sh`.
  - Prints what it could not decide as prominently as what it could —
    contradictions, ranks that paired with nothing, and every event it read
    nothing out of.

- `src/Command/TeamCommand.php`
  - `app:team list|claim` — the entrants of the 2v2 events, and who was in one.
  - `list` shows every team, its rank and its members, and counts the unclaimed
    ones rather than leaving them to be noticed.
  - `claim` refuses a blader nobody has heard of and an entrant the bracket never
    recorded; it creates neither. Claiming the same people again reports itself
    and writes nothing, so `repeat.sh` replays whole.
  - Writes its replay command inside the flush transaction, naming the bladers
    under the names the database holds.

### Application services

Both the web and CLI entry points are thin: they gather input, then hand it to a
service that owns the domain rules.

- `App\Service\TournamentImportService`
  - The single source of truth for the knockout bonus; the points matrix lives in
    `App\Service\F1Points`, because a team claim awards it too.
  - Resolves or creates players case-insensitively, builds the tournament and its results.
  - Accepts only strict `YYYY-MM-DD` dates, for web and CLI alike.
  - Writes the recovery artifacts inside the flush transaction, so the tournament
    and its ledger entry either both survive or neither does.
  - `importTeamEvent()` is the 2v2 path: one tournament, each entrant's rank
    expanded into one `TournamentResult` per blader in it, and no match, game or
    knockout bonus at all. An entrant with no members is stored and scores
    nothing. `bye` is dropped and nothing below it renumbers.

- `App\Service\TeamListParser`
  - Parses a roster file — `team: blader + blader`, one entrant per line in
    finishing order — into `App\Dto\TeamPlacement` objects. A trailing colon with
    nothing after it is an unclaimed team.

- `App\Service\TournamentTeamService`
  - The only thing that claims a team: it attaches bladers to an entrant that is
    already on record, writes their placements retroactively and awards that
    rank's points, inside the flush transaction with its ledger line.
  - Never creates a blader (unlike an import, it is filed long after the event),
    never creates a team, and never lets a blader finish twice in one event.

- `App\Service\F1Points`
  - What a finishing rank is worth. Read by the import and by a team claim, so
    the ten numbers exist once.

- `App\Service\PlacementListParser`
  - Parses an ordered placement list into `App\Dto\TournamentPlacement` objects.
  - Shared by the CLI file reader and the web textarea, so both accept `Name` and `Name,bonus` rows.

- `App\Service\ImportFileWriter`
  - Materialises a web-submitted placement list into `var/data/imports/`, so the
    ledger replay command always has a source file to point at.
  - `writeTeams()` does the same for a roster, in the shape `TeamListParser`
    reads back.

- `App\Service\ChallongeFetcher`
  - The only class in the app that touches the network. GETs the bracket's module
    page and returns an `App\Dto\ChallongeSnapshot`.
  - Runs the smoke check on the response before anything is parsed or written, so
    every path that ever reads a bracket is gated by it — the fetch command today,
    the import screen when it arrives.
  - Fails with what it expected and what came back, so a bot check or a moved
    endpoint reads as such rather than as a parse error.

- `App\Service\ChallongeSmokeCheck`
  - Asks a module page whether it is still the page this app reads: an HTML
    response, a tournament store that decodes, a tournament with a format, rounds,
    matches carrying `player1`/`player2`/`scores`/`winner_id`, and a standings
    table wherever the bracket's own shape puts it.
  - Reports every expectation as an `App\Dto\ChallongeSmokeReport` rather than
    throwing at the first one, because a checklist is what makes a Challonge change
    diagnosable. Only the page and the store are prerequisites; everything after
    them is looked for independently.
  - Checks the stage that orders the event, not the cut. A cut nobody has played
    yet is a bracket mid-event, not a route that has changed.

- `App\Service\ChallongeModuleParser`
  - Brace-matches the `_initialStoreState['TournamentStore']` object out of the
    page's JavaScript, ignoring braces and escaped quotes inside strings, and lifts
    the `#scorecard` standings table out of the page body.

- `App\Service\ChallongeStandingsParser`
  - Turns a rendered standings table into rows. Rank, participant, linked Challonge
    account and match history are read by name; every other column is kept verbatim
    under its own header label, because the set of them changes with the format and
    the `Byes` column exists only in the brackets that had byes.

- `App\Service\ChallongeStoreNormaliser`
  - Flattens Challonge's three bracket shapes into one list of stages, and drops
    what only the embed needs. Also picks up the third-place playoff, which hangs
    off the store rather than sitting in `matches_by_round`.

- `App\Service\ChallongeSnapshotWriter`
  - Materialises a snapshot into `var/data/challonge/`, which is tracked by git for
    the same reason `var/data/imports/` is.
  - Assembles the file beside its target and moves it into place, so a failure
    never leaves half a snapshot under the real name.

- `App\Service\ChallongeSnapshotFiles`
  - Where a captured bracket lives — `var/data/challonge/<slug>.json` — held in one
    place, because the writer and the reader both need it.

- `App\Service\ChallongeSnapshotReader`
  - Reads a captured bracket back out of its file. Everything downstream comes
    through here rather than through Challonge, which is what lets `repeat.sh`
    replay offline.
  - Refuses a version it does not read, a field that has changed type and a stage
    kind it has never heard of, rather than coercing any of them into something
    plausible.

- `App\Service\ChallongeStandingsResolver`
  - Joins each standings row to the entrant it is about, by intersecting the
    players of the matches in the row's match-history cell. A name join cannot do
    this: a blader who linked their Challonge account is rendered as that account
    in the standings and under their own name in every match.
  - Falls back to the name where the intersection cannot decide — a row with one
    match narrows to two people, and the standings table of a one-stage bracket
    carries no match history at all.

- `App\Service\ChallongeFields`
  - The type guards both ends of the pipeline read decoded JSON through. Absent and
    null are ordinary; present-and-the-wrong-type refuses, naming the field.

- `App\Service\AliasNormaliser`
  - Folds a display name to the part of it that is identity: case, punctuation,
    spacing and Challonge's `(invitation pending)` suffix, which one bracket
    managed to append twice. Over the eighteen captured brackets that takes 207
    distinct spellings to 129. It deliberately goes no further — `Obelix` and
    `Obelisk` are two letters apart and are two people.

- `App\Service\AliasResolver`
  - Turns a display name into the blader it belongs to, or into a question. It
    never creates anybody: an unresolved name comes back with suggestions
    attached and the caller stops, which is what keeps a seventy-seventh blader
    out of the table.
  - A name that reaches *two* people is refused the same way. A blader created
    from a placement list can shadow an alias filed before they existed, and
    preferring either would split a career across two rows in silence.
  - Suggestions are offered and never applied — an exact hit on the Challonge
    account a bracket rendered in place of the name, a known spelling within two
    edits, and a known name one spelling is built on (`BladerZ` inside
    `BladerZMLT`). The shortlist is ordered deterministically, blader name last.
  - `resolveAll()` answers a whole bracket off one index, each name carrying its
    own linked account.

- `App\Service\AliasService`
  - The only thing that writes to the alias table. Refuses a spelling that folds
    onto a blader's own name, so aliases and blader names stay one namespace and
    nothing downstream has to decide which wins. Two rows for one person is a
    merge, not an alias.
  - That guard covers the alias side only; the resolver covers the other by
    refusing a collision rather than picking a side, until #54 stops the console
    commands inventing bladers.
  - Builds one `AliasIndex` per write and threads it through both the blader
    lookup and the namespace check, which is what `app:bootstrap-aliases` comes
    through once per alias it seeds.

- `App\Service\AliasBootstrapper`
  - Derives the alias table from the league's own history and applies it through
    `AliasService`, one row at a time, so each seeded alias is checked and
    ledgered like a typed one.
  - Writes nothing two events disagree about, nothing the normaliser already
    folds, nothing already on file, and nothing that would point one blader's
    name at another. All four are reported instead.
  - Reads nothing out of a **team event**. Its entrants are teams, so a name in
    one belongs to two bladers rather than to one, and there is no pairing to
    learn. A team event is the one carrying `--team` on its import line.

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
  - Holding teams is what makes it a team event; nothing else marks one.

- `App\Entity\TournamentTeam`
  - One entrant of a 2v2 event: the name the bracket carried, kept verbatim, the
    folded form it is looked up by (unique per tournament), and its rank.
  - A team belongs to the event rather than to the league — Sk3lli was in
    `legion` on 11 July and `Lopez` on 19 July — so the tournament is part of the
    key and there is no `Team` entity.
  - **No members is unclaimed**, which is a record rather than a gap: the team
    keeps its rank, scores nothing, and can be claimed later.

- `App\Entity\TournamentTeamMember`
  - One blader in one event's team, and nothing else. Points reach them through
    a `TournamentResult` written at the team's rank.

- `App\Entity\TournamentResult`
  - Associates a player with a tournament finish.
  - Stores rank, `f1Points`, `bonusPoints`, and derived `totalPoints`.

- `App\Entity\PlayerAlias`
  - One spelling a blader has appeared under, unique on its normalised form, with
    the source that recorded it (`manual`, `seeded`, `challonge-account`).
  - Both spellings are stored: `alias` verbatim so a row can be recognised,
    `normalised` because that is what it is looked up by.

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
