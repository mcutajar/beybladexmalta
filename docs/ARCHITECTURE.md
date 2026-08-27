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
  - Admin form for importing a ranked tournament list typed by hand.
  - Uses `TOURNAMENTS_ADMIN_PASSPHRASE` for form-based admin gating.
  - Requires at least one placement — the F1 matrix pays nothing below tenth
    rather than refusing to be asked — then delegates to `TournamentImportService`.

- `src/Controller/AdminBracketImportController.php`
  - The second way into `/admin/import`: paste a Challonge URL, review what the
    bracket says, confirm.
  - Three requests and only the last one writes. The fetch is unauthenticated
    because reading a public page writes nothing; the passphrase is checked on
    the confirm.
  - The snapshot lives in the session between the two (`BracketDraftStore`), so
    the bracket that was approved is the bracket that is imported.
  - Posts back three things and no facts: the decisions, the finishing order and
    the knockout winner. Everything else is re-derived from the snapshot, which
    is why there is nothing to sign.

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
    landing pages' link cards and points matrix, the four-up figure strip
    (`KpiRow`), the label-and-number list (`TotalsList`), and the two blocks
    that say what an action will write (`ArtifactList`, `LedgerLine`).
  - `/_styleguide` renders all of them in every variant, in dev and test only.

- `templates/form/theme.html.twig`
  - Puts `.field` on every widget so form types hold no presentation.

### CLI support

- `src/Command/CreateBladerCommand.php`
  - Puts a blader on record and nothing else.
  - Exists for the replay: `var/data/imports/*.txt` stops at ten, and most of the
    bladers the import screen creates finished eleventh or worse, so they are
    named nowhere else in `repeat.sh`.
  - Running it twice is a no-op.

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

- `src/Command/ArchiveChallongeCommand.php`
  - `app:archive-challonge <slug|url>` — writes a captured bracket's stages,
    entrants, matches and games against the event that was imported from it.
  - Offline: it reads the tracked snapshot and never Challonge, so unlike
    `app:fetch-challonge` it **does** write a ledger line and a replay rebuilds
    every match without asking whether the brackets still exist.
  - The bracket names the event rather than the other way round, through the
    `--challonge` URL every import records. Two events naming one bracket is
    refused rather than guessed at.
  - A 2v2 event is reported and exits zero: a backfill walks every event, and
    two of them are team events.

- `src/Command/VerifyChallongeCommand.php`
  - `app:verify-challonge <slug|url>` — re-fetches a bracket and says what it has
    changed since it was captured, field by field, everything but `fetched_at`.
  - Fetches from the URL the snapshot recorded, so a capture made through an
    invite link or a subdomain is re-read the same way it was read the first time.
  - Writes nothing — no snapshot, no rows, no ledger line — and exits non-zero
    when the bracket has changed, so a cron can shout. Refreshing the record is
    still `app:fetch-challonge`, deliberately.

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
  - A blader named in two entrants keeps both places and is scored once, at the
    better rank. Members are resolved through one case-folded index, matching
    `PlayerRepository::findByName()`, so two spellings of the same person are
    the same person — including one the league has never heard of, who would
    otherwise become two rows the unique index rejects.
  - Returns an `App\Dto\TeamImportOutcome` rather than the bare enum. A roster's
    lines and the rows it produces are not the same number, and a command that
    recounted the file to describe what happened would reimplement all three
    rules that make them differ.

- `App\Service\TeamListParser`
  - Parses a roster file — `team: blader + blader`, one entrant per line in
    finishing order — into `App\Dto\TeamPlacement` objects. A trailing colon with
    nothing after it is an unclaimed team.

- `App\Service\TournamentTeamService`
  - The only thing that claims a team, and the writing half of the two tables;
    reads go through `TournamentTeamRepository`. It attaches bladers to an
    entrant that is already on record, writes their placements retroactively and
    awards that rank's points, inside the flush transaction with its ledger line.
  - Deduplicates the names one claim reaches, because the alias table makes
    `Obelisk` and `Obelix` easy to type in the same breath.
  - Never creates a blader (unlike an import, it is filed long after the event)
    and never creates a team.
  - Refuses a blader already on the board for that event — the one place that is
    refused rather than ruled on. The import cannot refuse, because a roster
    arrives whole; a claim is typed one team at a time by somebody who can see
    the standing.

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

- `App\Service\ChallongeRecordReader`
  - Reads a standings row's columns into typed statistics — W-L-T, byes, score,
    Buchholz, TB and points differential. The snapshot keeps every cell verbatim
    under the header Challonge printed, so this is where a string becomes a
    number, when the file is read rather than when it is written.
  - Matches a label with its parenthetical stripped, because Challonge writes the
    scoring rule into the header: the same column is `Match W-L-T` in a round
    robin and `Match W-L-T (wins +1.0, ties +0.5)` in a Swiss stage.
  - A column that is absent, and a cell that does not parse, both read as absent.
    A zero would be a claim the bracket never made, and the standings of a cut
    carry no columns at all.
  - `Set Wins`, `Set Ties` and `Pts` are read by nothing and stay in the
    snapshot. The round robin's `Pts` is a Beyblade-points total and the Swiss
    `Score` counts match wins; mapping one onto the other would state something
    no bracket said.

- `App\Service\ChallongeArchiveService`
  - Writes a captured bracket's stages, entrants, matches and games against the
    event that was imported from it. **Nothing here scores anything**:
    `TournamentResult` is untouched, so the leaderboard returns exactly what it
    returned before.
  - Archives **everyone**, not just the ten who scored. Ranks below eleven are
    half the matches and a blader's record is wrong without them.
  - **Idempotent by construction.** Every level has a natural key — a stage is
    its position, an entrant their Challonge id within the stage, a match its
    Challonge id within the tournament, a game its number within the match — and
    each is looked up before it is written. Rows the bracket no longer has are
    dropped, so re-archiving an edited bracket repairs rather than layers.
  - Refuses three things and writes nothing for any of them: a **team event**
    (whose entrants are teams and whose matches record only an aggregate), an
    event that records no bracket (an archive of it could never be replayed),
    and an event imported from a different bracket. A team event is recognised
    by the event holding teams *or* by the bracket setting `is_team` — the
    second never happens in the corpus, but believing it costs nothing and
    ignoring it would archive team names as participants.
  - Resolves each entrant through `AliasResolver` and never creates anybody. A
    name that reaches nobody is archived under the spelling the bracket used,
    attached to no blader, and reported so the alias can be filed.
  - A name that reaches **two** bladers is reported separately, in
    `AliasResolution`'s own words. It is the opposite problem and has the
    opposite answer: no alias can settle it, because `AliasService` refuses a
    spelling that folds onto a blader's own name. Two rows for one person is a
    merge.
  - Writes its ledger line inside the flush transaction, like every other admin
    action.

- `App\Service\BracketPreviewer`
  - Turns a captured bracket into the screen somebody approves, and writes
    nothing. Counts, decisions, the finishing order with the F1 matrix applied,
    what the archive will hold, and the exact lines a confirm appends.
  - Rebuilt from the snapshot on every request, answers included, so the render
    that refuses a confirm is the render that offers one.
  - **The ledger line is borrowed, not composed.** `LedgerService` owns the
    command strings and hands them back unappended, so the screen shows what
    will actually be written.
  - Refuses three brackets outright, each by name: a **team event** (its
    entrants are teams, and nothing in it says who was in one), one an event
    **already names** (`app:import-tournament` has no guard of its own and a
    second import doubles the event), and one with **no standings** (it states
    no finishing order).

- `App\Service\BracketImportService`
  - Everything a confirmed preview writes, in the order a replay would: bladers,
    aliases, the snapshot, the tournament with its ten scoring results, then the
    archive.
  - Rebuilds the preview rather than trusting one. The screen posts back
    decisions, an order and a knockout winner; every consequence of those is
    derived here from the snapshot the server kept.
  - **Nothing is written until every unresolved name is answered**, checked
    against the rebuilt preview. The date and the season are checked before the
    first write, so a refusal never leaves an invented blader behind.
  - Scores the top ten only. Writing results below eleventh would put rows in
    `tournament_results` that `getLeagueLeaderboard()` counts against each
    blader's best fourteen.

- `App\Service\BladerService`
  - The one place a blader is created deliberately, and the reason
    `app:create-blader` exists. Never files an alias; that is the other half of
    the same answer.

- `App\Service\BracketDraftStore`
  - Holds the fetched bracket in the session between the preview and the
    confirm. `var/data/challonge/` is the record, so a bracket nobody has
    approved does not go there — and re-fetching on confirm would mean
    approving one bracket and importing whatever was being served a minute
    later.

- `App\Service\ChallongeEventFinder`
  - Which event, if any, came from a given bracket. Asked three times — by the
    archive command, by the preview's already-imported guard, and by the import
    that has to find the tournament it just created — so it lives in one place.

- `App\Service\ChallongeSnapshotDiffer`
  - Compares a captured bracket against a freshly fetched one, everything except
    `fetched_at` — which is the field a fetch rewrites, and the reason
    "re-fetch it and read the git diff" does not answer the question.
  - A subtree that exists on one side only is reported where it appears rather
    than descended into, so a stage the bracket has gained is one line.

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

- `App\Entity\TournamentStage`
  - One stage of an event's bracket — the Swiss rounds everybody played, the cut
    that followed, or the single stage that was the whole tournament — with its
    kind, format and round count.
  - The root of the archive: entrants and matches cascade from it, so
    `TournamentStageRepository` is the archive's one door.
  - Matches cascade a remove but are **not** orphan-removed, because a match can
    move between stages when a bracket is restructured upstream and orphan
    removal cannot tell a move from a deletion. A match the bracket has really
    dropped goes through `TournamentStageRepository::discardMatch()`.
  - Keyed by `position`, the order the stages were played. Everything else about
    a stage, its kind included, can be corrected upstream.

- `App\Entity\TournamentParticipant`
  - One entrant of one stage: the name the bracket used, the Challonge account
    rendered in place of it, the seed, and what the standings row said — stage
    rank, `Advanced`, W-L-T, byes, score, Buchholz, TB and points differential.
  - `player` is nullable, because resolution never invents anybody. An
    unrecognised spelling is a missing alias, and re-archiving after it is filed
    picks the entrant up.
  - Ids are per stage: Challonge numbers a group stage and its cut in disjoint
    spaces, so a blader who played both is two rows.

- `App\Entity\TournamentMatch`
  - One match: stage, round, identifier, both entrants, the scoreline, the
    winner and the loser, whether it was forfeited and whether it was the
    third-place playoff.
  - Unique on `(tournament, challonge_id)` — the idempotency the archive is built
    around.

- `App\Entity\MatchGame`
  - One game inside a match, written **only** when a match had more than one.
    Every played solo match in the corpus is a single game, so the table starts
    empty on purpose; the rule lives on `TournamentMatch::transcribeGames()` so
    that a caller cannot forget it.

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
