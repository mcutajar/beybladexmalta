# Releasing

Every deploy used to be a build. `make deploy` rebuilt the image from whatever
was in the production checkout, tagged it `app-php:latest`, and recreated the
container — so the previous image became dangling, the next prune deleted it,
and there was no way to answer "what is live?" beyond "whatever was checked out
when somebody last ran it". Going back a version meant checking out an older
commit and hoping the build still worked.

Releases are named now, and the name is a git tag.

## The shape of it

```
make release VERSION=1.1.0     →  pushes the tag v1.1.0
       ↓
GitHub Actions                 →  tests the tagged commit, builds the production
                                  image, pushes ghcr.io/mcutajar/beybladexmalta:1.1.0
       ↓
make deploy VERSION=1.1.0      →  pulls that image and restarts production
```

Three properties fall out of that, and they are the point of the whole
arrangement:

- **Production runs an artifact, not a build.** The image was built once, by CI,
  from a tagged commit whose suite passed. Deploying it twice deploys the same
  bytes. Nothing is compiled on the production host any more.
- **Old versions still exist.** They are in the registry under their own tags,
  and nothing overwrites or expires them. That is what makes the next point
  cheap.
- **A rollback is a pull.** `make rollback VERSION=1.0.0` needs no source
  checkout, no build and no working network beyond the registry.

## Cutting a release

**From the main checkout**, with main current and everything pushed:

```bash
make release VERSION=1.1.0
```

Which is the opposite of where everything else here happens, and for a plain
reason: git will not check out `main` in a worktree while the main checkout
holds it, so the main checkout is the only place a release can be cut from.
`release` touches nothing but git — no Docker, no dev stack — so it is safe
there even while production is running.

It refuses anything that would produce a release nobody can reproduce — a
version that is not semver, a dirty tree, a `HEAD` that is not `origin/main`, a
version number already used — and checks CI's verdict on the commit before it
tags. What it does *not* do is re-run the suite locally: the commit is
`origin/main`, CI has already tested it on push, and the release workflow tests
it again before publishing. A local `make check` would test the same commit a
third time.

If CI on that commit failed, it refuses. If CI is still running, it says to wait.
If there is no verdict to find — or `gh` is unavailable — it says so and carries
on, because a broken lookup is not a reason to block a release.

Before it tags, it rewrites `CHANGELOG.md` from the commits, commits that as
`chore(release): v1.1.0` and pushes it to main — so the tag's own tree contains
the entry describing it. That is the only part of `make release` that is not
pure git: git-cliff runs from a pinned Docker image, because it is not a PHP
tool and nothing gets installed on the host.

Watch what it triggers with `gh run watch`. When the run is green the image
exists and there is a GitHub Release whose notes are the same section that just
went into `CHANGELOG.md`.

Version numbers are never reused. If a release goes wrong, the fix is the next
number, not the same one again.

### What to bump

Standard semver, read against the people who use this:

| Bump | For |
| --- | --- |
| **Major** | Anything that needs a hand at deploy time — a schema rebuild, a new required environment variable, a changed deploy procedure. |
| **Minor** | A new page, command, import format or admin flow. |
| **Patch** | Fixes and content corrections that need nothing but the deploy. |

The schema is not versioned by migrations (see `AGENTS.md`), so a release that
changes the entity mapping is a major: deploying it means taking the site down,
dropping the database, recreating the schema and replaying `repeat.sh`. That
sequence is not something a patch release should ever imply.

## Where the changelog comes from

The commits. `cliff.toml` maps conventional types to groups, and two things
render it:

- **`make release`** writes the whole of `CHANGELOG.md`, with the commits since
  the last tag rendered under the version about to be cut.
- **The release workflow** renders just that newest section into the GitHub
  Release body, under the image name and the deploy command.

Same config, same commits, same pinned git-cliff, so the file and the release
notes cannot say different things. Preview what the next release will read like
without cutting one:

```bash
make changelog VERSION=1.1.0
```

That writes the file rather than printing it, so a preview leaves `CHANGELOG.md`
modified; `git checkout -- CHANGELOG.md` puts it back. `make changelog` with no
version rewrites the released history only — useful after editing `cliff.toml`,
and a no-op otherwise.

It used to be `gh release create --generate-notes`, which builds its list out of
**pull request titles**. That is the worse of the two sources this repository
has: one flat list, Dependabot bumps interleaved with features, and none of the
commit bodies, which is where the reasoning actually lives.

### The decisions behind it

**Pull requests are rebase-merged, not squashed.** This is what makes the commit
log worth reading in the first place — a four-commit branch lands as four
commits, each keeping its type and its body. Squashing is still enabled in the
repository settings as an escape hatch, but using it costs this release its
changelog: `squash_merge_commit_title` is `PR_TITLE`, which is not a conventional
subject, and `squash_merge_commit_message` is `BLANK`, which discards the bodies.
The result would be exactly the pull-request-title list this replaced. If squash
ever becomes the default, two settings have to change with it — the message
source to `COMMIT_MESSAGES`, plus a pull request title lint, since the title
becomes the commit subject.

**A commit that does not parse is grouped, not dropped.** `filter_commits` and
`filter_unconventional` are both off, and the last parser in `cliff.toml` matches
everything into an `Other` group. An untidy entry in the changelog is a much
smaller problem than a change that silently is not in it. There is no commit
message lint: 85 of the 87 commits at the time of writing already parsed, and the
two that did not are `Initial commit` and its immediate successor.

**Only released versions are in the file.** An `Unreleased` section would be
correct until the next commit landed on main, and nothing regenerates the file on
a push — so it would spend most of its life quietly wrong. `make changelog
VERSION=…` is the preview instead.

**Dependabot's commits are skipped**, on the `chore(deps)` prefix that
`.github/dependabot.yaml` sets. They are half the volume of some releases and
none of it is news about the site; the bumps are still in the log, and in the
pull requests, for anyone looking for them.

## Deploying

From the **production checkout**, never a worktree:

```bash
make deploy VERSION=1.1.0
```

That pulls the published image, recreates the container, waits for the
healthcheck and then proves the deploy landed. `make deploy` with no `VERSION`
falls back to the release tag on `HEAD`, and fails if there is not one — it will
not guess.

`make versions` lists the releases and marks the live one. `make prod-version`
prints just the live one, which is read from the running container's
`org.opencontainers.image.version` label rather than from anything on disk.

## Rolling back

```bash
make rollback VERSION=1.0.0
```

Which is `make deploy` under another name, because that genuinely is the whole
procedure. The older image is still in the registry.

The one thing a rollback does not undo is the database. The schema is rebuilt
from the entity mapping rather than migrated, so going back across a release
that changed the mapping means rebuilding the schema from the older code and
replaying `repeat.sh` as well. Within a run of releases that did not touch
entities, a rollback is only the image.

## Secrets are not in the image any more

They used to be. The build ran on the production host, `.env.local` sat in the
build context, and `composer dump-env prod` compiled it into `.env.local.php`
inside a layer. That worked precisely because the image never left the machine.

A published image is a different proposition — this repository is public, so its
packages are too — and CI has no `.env.local` to bake in even if it were not.
So the flow inverted:

- `.dockerignore` excludes `.env.local`, so no build can pick it up.
- `compose.yaml` passes `APP_SECRET`, `DATABASE_URL`, `DEFAULT_URI` and both
  admin passphrases into the container as environment variables, read from the
  host's `.env.local` through the Makefile's `--env-file` pair.
- Symfony's Dotenv never overwrites a variable that is already set, so those
  win over the committed defaults the image carries.
- `make deploy` runs `make deploy-preflight` first and refuses to start if any
  of them is empty. It reports the names and never the values.

**An empty admin passphrase used to mean an open door.** `hash_equals('', '')`
is `true`, so a container that never received `PAYMENTS_ADMIN_PASSPHRASE` would
have accepted an empty form field. That was unreachable while the passphrases
were baked in and became reachable the moment they arrived at run time, so
`AdminPassphraseVerifier` now refuses everything when the configured passphrase
is empty, and logs it as critical. The preflight check is the first line; that
is the second.

## Where the history lives

`ghcr.io/mcutajar/beybladexmalta`, one tag per version, listed on the
repository's packages page. Nothing prunes it and nothing should: the point of
keeping the images is that a version from a year ago can still be started.

There is no `latest` tag, on purpose. Every deploy names a version, so a moving
tag would only ever be a way to deploy something without recording what it was.

## Things that will surprise you

- **The image is `linux/arm64` only.** Production is Docker Desktop on Apple
  Silicon, and the release job runs on an arm64 runner to build that natively.
  Pulling it on an x86 host will not work without emulation. Publishing a second
  architecture is a change to the `platforms` of the build step, worth making
  the day something else has to run it.
- **`compose.yaml` has no `build:` key.** That is deliberate. With one, a bare
  `docker compose -f compose.yaml up -d` would build the working copy and stamp
  a release version on it, and production would be serving something no release
  ever produced. Without one, the same command fails on a missing manifest.
- **The default image tag is `:none`.** Nothing publishes that tag, so a command
  that forgot to name a version fails instead of picking one.
- **An image built by hand reports `0.0.0-dev`.** The version label is set from
  a build argument that only the release workflow passes, which is how
  `verify-deploy` can tell a release from something somebody built locally.
- **The tag is one commit past what `release-gate` checked.** The gate reads
  CI's verdict on `origin/main`, and then the changelog commit is pushed and
  tagged. The difference is one generated markdown file, and the release
  workflow runs the whole suite on the tag before it publishes an image — but
  it is why "the tag is exactly the commit CI tested" is not literally true.
- **The publish job checks out at `fetch-depth: 0`.** git-cliff needs the
  history and the previous tag to have anything to diff against. Miss it and
  nothing fails: the release just gets an empty set of notes.
- **The changelog could not be pushed by the workflow.** It is generated by
  `make release` instead, because main's ruleset requires the `PHPUnit` check on
  a push and only repository admins bypass it. A push made with `GITHUB_TOKEN`
  does not trigger workflows, so the check that commit needs would never run and
  the push would be refused every time.
- **`make release` and `make deploy` both run from the main checkout**, which
  makes releasing the exception to "work in a worktree". Not by preference:
  `main` cannot be checked out in a worktree while the main checkout has it, and
  a release has to be cut from main. Neither target starts a dev stack, so
  neither is a danger to the production container in that directory.
