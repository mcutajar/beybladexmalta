---
name: release-and-deploy
description: How to cut a release, publish the image, deploy it to production and roll back. Use when asked to release, tag a version, bump the version, deploy, roll back, check what production is running, or when changing compose.yaml, the Dockerfile, cliff.toml, the release/deploy Makefile targets or the release GitHub workflow.
---

# Releasing and deploying

`docs/RELEASING.md` is the full procedure and the reasoning; read it before
cutting anything. These are the properties that must survive any change.

**Releases and deploys run from the main checkout, not a worktree** — the one exception
to this project's worktree rule. Git will not check out `main` in a worktree
while the main checkout holds it, and a release has to be cut from main.
`make release` starts no dev stack, so it is safe to run beside a running
production container.

A deploy is no longer a build. **A git tag publishes an image; a deploy pulls
one.** `docs/RELEASING.md` is the full procedure — the short version:

```bash
make release VERSION=1.1.0   # from the main checkout: tags and pushes
                             # CI then tests, builds and pushes the image
make deploy VERSION=1.1.0    # from the main checkout: pulls it and restarts
make rollback VERSION=1.0.0  # the same thing, pointed at an older version
```

`make versions` lists the releases and marks the live one; `make prod-version`
prints what production is running, read from the container's
`org.opencontainers.image.version` label.

Things that are load-bearing here:

- **`compose.yaml` has no `build:` key, and must not gain one.** With one, a bare
  `docker compose -f compose.yaml up -d` builds the working copy and stamps a
  release version on it, and production serves something no release produced.
  Without one, that command fails on a missing manifest. The default tag is
  `:none` for the same reason — nothing publishes it, so a command that forgot
  to name a version fails rather than picking one.
- **Never deploy with a bare `docker compose`.** In this checkout it also reads
  `compose.override.yaml`, which builds the `frankenphp_dev` target and
  bind-mounts the working copy over `/app`. Every production command names its
  file: `-f compose.yaml`. `make deploy` does this for you.
- **Version numbers are never reused.** A bad release is followed by the next
  number, not a retagged one.
- **`make release` is cut from the main checkout, not a worktree** -- the one
  exception to working in a worktree. Git will not check out `main` in a
  worktree while the main checkout holds it, and a release has to be cut from
  main. It starts no dev stack, so it is safe to run beside a production
  container; it does not re-run the suite locally, because CI has already
  tested the commit it is cutting from and the release workflow tests the tag
  again before publishing.
- **The changelog is written before the tag, not after it.** `make release`
  regenerates `CHANGELOG.md` from the commits, commits it as
  `chore(release): vX.Y.Z` and pushes to main, and only then tags -- so the
  tag's tree contains the entry describing it. This cannot move into the
  release workflow: main's ruleset requires the `PHPUnit` check on any push,
  only repository admins bypass it, and a push made with `GITHUB_TOKEN` does
  not trigger the workflows that would report it. The cost is that the tagged
  commit is one past the one `release-gate` read CI's verdict for.
- **The image is `linux/arm64` only**, because production is Docker Desktop on
  Apple Silicon. The release job runs on an arm64 runner so that is a native
  build rather than a QEMU one.
- **An image built by hand reports version `0.0.0-dev`.** The label comes from a
  build argument only the release workflow passes, which is what lets
  `verify-deploy` tell a release apart from a local build.

### The release notes are the commits

`cliff.toml` turns the conventional commit types into groups, and both the
GitHub Release body and `CHANGELOG.md` are rendered from it -- the workflow
renders the newest section, `make release` renders the file. Nothing is written
by hand and `--generate-notes` (pull request titles) is gone.

Three consequences worth carrying:

- **A commit subject is published.** It ends up in a release note and in a file
  people read, so it is worth the same care as the code. The body is not
  rendered, and is still where the reasoning belongs.
- **Nothing is dropped.** A commit that does not parse lands in an `Other`
  group rather than vanishing, on purpose.
- **Pull requests are rebase-merged.** Squashing is still enabled but would
  degrade the changelog to a single pull request title with a blank body, which
  is what this replaced. `docs/RELEASING.md` records the reasoning.

git-cliff is not a PHP tool, so it is not in the dev container; `make changelog`
runs it from a pinned image, which keeps the host clean the same way the
container rule does.

### Secrets are supplied at run time, not baked in

They used to be baked in: the build ran on the production host, `.env.local` was
in the build context, and `composer dump-env prod` compiled it into
`.env.local.php` inside a layer. A published image is public and CI has no
`.env.local`, so that route is closed at both ends.

`.dockerignore` excludes `.env.local`, `compose.yaml` passes `APP_SECRET`,
`DATABASE_URL`, `DEFAULT_URI` and both admin passphrases into the container from
the host's env files, and Symfony's Dotenv leaves an already-set variable alone
so those win over the image's committed defaults. `make deploy` runs
`deploy-preflight` first and refuses to start when one is empty, naming the
variable and never printing a value.

**An unset admin passphrase is an open door, not a locked one.**
`hash_equals('', '')` is `true`, so a container that never received
`PAYMENTS_ADMIN_PASSPHRASE` would accept an empty form field. That became
reachable the moment passphrases stopped being baked in, so
`AdminPassphraseVerifier` refuses everything when the configured passphrase is
empty and logs it as critical. Any new passphrase-gated flow goes through it
rather than calling `hash_equals()` directly.

### Why `make deploy` verifies rather than just restarting

Three things can go wrong without the site going down, and the first two have:

1. **The image ships code whose dependencies were never installed.** The kernel
   cannot boot, and the site 502s -- but only once something forces it to boot
   fresh. `verify-deploy` runs `bin/console about`, which fails loudly instead.
2. **A compiled cache outlives the code it was compiled from.** Symfony never
   revalidates the container, the routes or Twig in production, so the site goes
   on serving an older build and every release looks like a no-op. This happened
   for a month: `compose.yaml` mounted a volume over `/app/var`, hiding the
   warmed cache the image ships (the Dockerfile copies `/app/var` in as its own
   layer) behind one compiled in July. `verify-deploy` fails if any file under
   `src/`, `config/` or `templates/` is newer than `var/cache/prod`.
3. **The container that came up is not the version that was asked for.**
   `verify-deploy` compares the running container's version label against the
   version the deploy named.

Nothing may be mounted over `/app/var` in production. Only the two directories
the app writes to at runtime are mounted, and both are named: `LedgerService`
appends to `var/log`, `ImportFileWriter` writes `var/data/imports`.

If a deploy fails verification, `make prod-logs` is the next stop.
