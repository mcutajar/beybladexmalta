---
name: challonge-import
description: Rules for reading, snapshotting, importing and archiving Challonge brackets, and for resolving Challonge display names onto bladers. Use when touching ChallongeFetcher, ChallongeUrl, the archive services, AliasResolver/AliasService/AliasNormaliser, TournamentStage/Participant/Match/MatchGame, TournamentTeam, /admin/import and its bracket preview, or any app:*-challonge, app:import-tournament, app:team, app:bootstrap-aliases or app:create-blader command.
---

# Importing a Challonge bracket

Every rule below is load-bearing and most were paid for. `docs/ARCHITECTURE.md`
has the fuller picture of the services involved.

## Reading a bracket

Challonge's human-facing pages return **403** to anything that is not a browser,
and so does `/<slug>/standings`. Only `challonge.com/<slug>/module` answers a
plain client, and it carries the whole tournament in a
`_initialStoreState['TournamentStore']` assignment. Send a User-Agent that names
the site — an anonymous client is bounced — and keep `show_standings=1` on the
URL, because without it a Swiss bracket renders no standings table at all and
nothing fails until something tries to read one.

`challonge.com/<slug>` *does* resolve a bracket that lives on a subdomain, by
301, but the redirect drops the query string. `ChallongeUrl` therefore keeps the
subdomain rather than letting the client follow the hop.

The group stage and the final stage of the same bracket use **disjoint id
spaces**. A blader who plays both appears under two unrelated ids with only
their display name in common, so a snapshot lists participants per stage and
never merges them.

The **third-place playoff is not in `matches_by_round`**. It hangs off the store
as `third_place_match`, and again as `consolation_matches`. Miss it and every
bracket with a cut is one match short; merge it in unflagged and it looks like
the final, which is how the knockout winner is identified.

A standings row does not reliably carry the participant's name: a blader who
linked their Challonge account is rendered as **that account instead**. Rows are
joined to participants through the match ids in their match-history cell.

## The rules

- **Nothing reads a Challonge bracket without the smoke check.** It lives inside
  `ChallongeFetcher`, ahead of the parse and the write, so an import cannot begin
  on a page that has changed shape — and a path added later inherits the gate
  rather than having to remember it. `app:challonge-smoke` runs the same check on
  its own, against a live bracket, a page saved with `--file`, or on a cron.
  A consequence worth knowing: a bracket whose ranking stage renders no readable
  standings can no longer be captured at all. That is deliberate — there is no
  finishing order to import out of one — and `app:challonge-smoke` is how such a
  page is looked at instead.
- **A Challonge snapshot is a transcription, not an interpretation.**
  `var/data/challonge/<slug>.json` keeps every fact the bracket stated — every
  match with its per-game scorelines, the entrants, the standings tables column
  for column — and none of what only the embed needs. What it must never gain is
  a conclusion: no column renamed into our vocabulary, no display name resolved
  to one of our players. Those change, and a tracked file cannot. Turning a
  snapshot into domain objects happens when it is read, where a mistake costs a
  re-parse rather than a re-fetch of a bracket that may be gone.
- **The archive is additive, and the scoring record is not part of it.**
  `TournamentStage`, `TournamentParticipant`, `TournamentMatch` and `MatchGame`
  hold the nine hundred and fifty-one matches the placement lists threw away —
  and `TournamentResult` keeps the ranks and the points exactly as it did, so
  `PlayerRepository::getLeagueLeaderboard()` returns the same rows before and
  after `app:archive-challonge`. A tournament nobody has archived is not a
  broken one. Everyone is archived, not only the ten who scored: ranks below
  eleven pay nothing and are half the matches.
- **Archiving twice writes the same rows, and that is load-bearing.** Every
  level has a natural key — a stage is its position, an entrant their Challonge
  id within the stage, a match its Challonge id within the tournament, a game
  its number within the match — and each is looked up before it is written, so
  re-archiving a bracket that was corrected upstream repairs the record rather
  than layering a second copy over it. `app:import-tournament` has no such
  guard, which is exactly why a second replay of `repeat.sh` doubles every
  result it holds.
- **A game row is written only when a match had more than one game.** Every one
  of the 947 played solo matches in the corpus is a single game, so a row per
  game would restate its own match's scoreline 947 times; all fifty-one
  multi-game matches are team matches, and a team event archives its entrants
  and nothing else. So `match_games` starts empty on purpose, and the rule
  lives on `TournamentMatch::transcribeGames()` rather than in the archive
  service — a path added later inherits it instead of having to remember it. A
  backfill that produced 947 rows is the sign it was bypassed.
- **A bracket that changed after it was imported is found by asking, not by
  noticing.** `app:verify-challonge` re-fetches one and diffs it against the
  snapshot, everything except `fetched_at` — which every fetch rewrites, so a
  re-fetch of an unchanged bracket produces one line of `git diff` and looking
  at that is not the same check. It writes nothing either way; capturing a
  change is still `app:fetch-challonge`, followed by archiving again.
- **A bracket is imported through a preview, and the preview writes nothing.**
  `/admin/import` has two ways in. The textarea is unchanged and stays — it is
  what you use when a bracket will not parse on an event night. The other pastes
  a URL, fetches, and renders a screen that proves which bracket came back,
  turns every name the league cannot read into a required decision, seeds the
  finishing order from the standings with the F1 matrix already applied, and
  shows the files and the exact `repeat.sh` lines a confirm will write. The
  snapshot is held in the session between the two requests, so the bracket that
  was approved is the bracket that is imported; the passphrase is checked on the
  confirm, because fetching a public page writes nothing. **Nothing is written
  until every unresolved name is answered**, and that is checked against a
  preview rebuilt on the server rather than against what the browser posted —
  which is why the confirm carries only choices and needs no signature.
- **Three brackets are refused outright, each by name.** A 2v2 event, whose
  entrants are teams and which is imported from a roster instead; one an event
  already names, because `app:import-tournament` has no guard and a second
  import doubles the evening; and one with no standings, which states no
  finishing order.
- **A question with nothing close to it arrives answered; one with a suggestion
  never does.** The two directions are not the same risk, and the asymmetry is
  the whole rule. An unnecessary blader is a duplicate row — visible in the
  list, and #56 merges it away. An unnecessary alias welds two people into one,
  there is no unmerge, and nothing on any page looks wrong afterwards. Measured
  against the 23 August bracket: fourteen names needed a decision, ten had
  nothing close, and of the four suggestions **one was two different people**
  (`Steve V.` is one edit from `Steve`). So the ten are seeded to *somebody new*
  and folded into a review disclosure, and the four are offered their suggestion
  first, loudest and one tap away — still a tap. There is deliberately no
  "accept all suggestions": at one in four wrong, a batch control would
  recreate exactly the risk the seeding rule avoids.
  Seeding is wrong sometimes too and the screen says so: `Orteborn` is three
  edits from `Otrebor`, past `AliasResolver::CLOSE_ENOUGH`, so nothing is
  suggested and the default is a duplicate unless somebody uses *Someone else*.
- **`BracketDecision` owns the default, and `wasSeeded()` is the same rule read
  backwards.** It is derived rather than transported: the screen renders a
  seeded row with its answer already selected, so the browser posts it back like
  any other and a "this was not looked at" flag would be one the browser
  controls. What is recorded is narrower and true either way — the answer is the
  default and it was not changed — and it is logged per blader created, because
  a duplicate that turns up three brackets later is one you want to be able to
  trace to an unexamined row.
- **Every decision row is buttons, with the same dropdown collapsed behind
  them.** The buttons carry the answers that are usually right, so those cost
  one tap. The dropdown reaches the rest of the league, and every row has it —
  a seeded row because the shortlist missed (`Orteborn` is three edits from
  `Otrebor`, past the threshold, and would default to a duplicate), and a
  suggested row because a confident suggestion can be the wrong person.
- **The dropdown is a separate field from the buttons, and a radio hands over
  to it.** A `<select>` sharing the field name posts alongside the radios and,
  being later in the document, wins — blanking a button somebody already
  pressed. Document order cannot express "whichever was touched last", and the
  one script this site ships may not be load-bearing, so the two are separate
  controls and
  `AdminBracketImportController::answersIn()` folds them back into one answer.
  It costs a second tap on the path that is taken rarely, which is the trade
  chosen deliberately over a control that can silently overwrite an answer.
- **An unselected button is not tinted.** The suggestion is recommended by being
  placed first and given the widest target, not by being coloured in before it
  is chosen — a shortlist that is wrong one time in four must not look already
  decided. The same rule kills the amber flag that used to sit on a placement
  whose Challonge spelling differed from the blader's name: that is the alias
  table working, and painting the normal case as a warning teaches people to
  ignore warnings.
- **The finishing order and the knockout winner are read, never asked.** The
  bracket's standings matched the hand-typed placement list on all eighteen
  captured events, and the last match of the cut matched the hand-typed
  `--knockout` on all sixteen that had one — so the screen states both and
  offers to overrule neither. The decisions are the only input, which is why
  `BracketAnswers` is the whole of what a confirm posts.
- **The placements follow the decisions, and `Update` is how you see that.**
  Resolving a name changes what the table says, and dropping an entrant as
  "not a person" moves everyone below them up and rescores them — the league's
  rank is a row's place in the list, not the number Challonge printed. So the
  confirm bar carries a second submit that re-derives the preview and writes
  nothing, needs no passphrase, and posts back to `#placements`. Doing it live
  in the browser would mean a second copy of the F1 rules in JavaScript, and the
  one script this site ships is the kind that can be switched off without taking
  anything with it.
- **The import screen is the only thing that creates a blader deliberately, and
  it gets a ledger line of its own.** `app:create-blader` exists because
  `var/data/imports/*.txt` stops at ten and most of the bladers this screen
  creates finished eleventh or worse — archived, unscored, named nowhere else in
  `repeat.sh`. Without the line they would exist until the next schema rebuild
  and then stop existing, taking every match attached to them with them. It
  replays before the aliases that spell its blader and the import that scores
  them, and running it twice is a no-op. Creating a blader is offered alongside
  the suggestions and never pre-selected; the count about to be created is shown
  before the button.
- **A spelling more than one blader answers to gets no answer on that screen.**
  It is the collision `AliasResolver` refuses to break, so the decision list
  states the problem and the import stays blocked. No alias can settle it: two
  rows for one person is a merge, and a blader whose name shadows an alias is
  the alias to remove.
- **The web import scores at least one place, not exactly ten.** The rule used
  to be exactly ten, which would have rejected the seven-entrant round robin
  already in the data. The matrix pays nothing below tenth rather than refusing
  to be asked, so a short list scores every place it has and a bracket import
  scores its top ten and archives the rest.
- **A Challonge display name is never turned into a blader.** Two hundred and
  seven spellings across the captured brackets belong to about seventy-six
  people, and `AliasNormaliser` only folds that to a hundred and twenty-nine —
  case, punctuation and `(invitation pending)`. The rest is `PlayerAlias`, a
  stored table somebody curates, because `Obelix` and `Obelisk` are two letters
  apart and are two people. `AliasResolver` returns an unrecognised name as a
  question with suggestions attached, and no caller may take a suggestion and
  act on it; `AliasService` is the only thing that writes, it refuses a spelling
  that folds onto a blader's own name, and it never creates a blader. Two rows
  for one person is a merge, not an alias.
- **That last rule guards only the alias side, on purpose.** Bladers also arrive
  by being invented from a placement list, which `app:import-tournament` still
  does, so a blader created later can shadow an alias filed before they existed.
  `AliasResolver` therefore treats a spelling that reaches two people — two
  blader rows, or a blader and an alias pointing elsewhere — as unresolvable
  rather than picking a side, because picking would split somebody's career
  across two rows silently. Closing it at the point of creation is #54's job;
  until then the collision is meant to be loud.
- **The table was not typed, it was read out of the imports.** Every event
  already imported is a labelled example — rank *n* of its captured bracket is
  line *n* of the placement list somebody typed at the time — so
  `app:bootstrap-aliases` derives the pairs, prints the lot, and writes only on
  `--force`. Fifteen rows came out of the sixteen non-team events and they are
  in `repeat.sh` like every other admin action. It writes nothing two events
  disagree about and creates nobody.
- **A team event teaches the alias pass nothing, and that is the phantom rule.**
  Its entrants are teams, so a name there belongs to two bladers rather than
  one. The five phantoms this used to produce — `JG1`, `JG2` and the literal
  `-`, `--` and `---`, padding invented to reach ten lines in the Player A and
  Player B lists — are gone, because the two 2v2 events are now one tournament
  each imported from a roster. Nothing is to be learned from a team event,
  nothing merged into one, and nothing resolved onto one.
- **A 2v2 event is one tournament, declared at import and expanded through a
  roster.** `app:import-tournament ... --team` reads a roster file — `team:
  blader + blader`, one entrant per line in finishing order — and awards the
  entrant's rank to every blader in it, by the same F1 matrix. No matches, no
  games, no knockout bonus: a team match records only the aggregate of its
  individual matchups, so there is no blader-level result to be had. Nothing is
  lost permanently; the snapshot keeps every match and every set.
- **An unclaimed team is a record, not a gap, and it is the only place the
  never-auto-create rule resolves to a row instead of a question.** `JG` and
  `melhina` finished tenth and eleventh on 11 July and nobody knows who was in
  either, so `TournamentTeam` holds them with no members: they keep their rank,
  score nothing, and never stopped the import. `app:team claim` attaches bladers
  afterwards, writes their placements and awards that rank's points — and it
  never creates a blader, because unlike an import it is filed long after the
  evening. A solo entrant nobody recognises in a 1v1 bracket still stops and
  asks.
- **A blader in two entrants of one event keeps both places and is scored
  once, at the better rank.** It is not supposed to happen and the league does
  not sanction it, but the roster is the record of who played with whom, so
  dropping half of it would lose that and awarding both would pay somebody
  twice for one evening. The import says whose name it was rather than
  deciding quietly. A **claim** refuses instead: it is typed one team at a
  time by somebody looking at the standing, so moving a placement that already
  exists is their decision rather than the command's.
- **`bye` is dropped and nothing renumbers around it.** It is an entrant of
  `uhxii7az` at rank 12 and the only placeholder entrant in all eighteen
  brackets. The ranks are Challonge's, so taking a row out never moves the ones
  below it. That is the line: `bye` goes because it is not an entrant, an
  unclaimed team stays because it is one.
- **`LedgerService` builds every command string, and hands them back
  unappended when asked.** The import preview shows what will land in
  `repeat.sh` before anything is written, and a screen that composed its own
  approximation would drift from the real line the moment either changed.

## Related

Doctrine's `orphanRemoval` behaviour bit the archive services hard; `AGENTS.md`
records the rule and `ChallongeArchiveServiceTest::rowIds()` is why it was visible.
