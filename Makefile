# Every target runs inside the dev container.
# Nothing here requires PHP, Composer or Postgres on the host machine.

COMPOSE_FILE   ?= compose.override.yaml
ENV_FILE       ?= .env
LOCAL_ENV_FILE ?= .env.local
SERVICE        ?= php

# Build artifact, not committed. Targets that render templates depend on it,
# so a fresh clone builds it once instead of failing with an AssetMapper error.
TAILWIND_CSS := var/tailwind/app.built.css

# The compiled dev container, which phpstan-symfony reads to resolve services
# and routes. Also a build artifact, also not committed.
CONTAINER_XML := var/cache/dev/App_KernelDevDebugContainer.xml

# Where "make coverage" writes its reports. Gitignored: every one of them is a
# product of the test run, and CI rebuilds them on each push.
COVERAGE_DIR ?= var/coverage

# PHPStan needs more than the 128M the container's php.ini gives CLI scripts.
PHPSTAN_MEMORY ?= 1G

# How long "setup" waits for the container's healthcheck. The first boot of a
# fresh clone runs composer install into the bind mount, which is the slow part.
HEALTH_TIMEOUT ?= 300

# A --env-file *replaces* the .env Compose would otherwise read, it does not
# layer on top of it. Passing only .env.local would therefore blank out every
# variable the committed .env defines -- DATABASE_URL among them, which starts
# the container with an empty DSN. Repeated --env-file flags do layer, later
# files winning, so both are passed with the local overrides last.
ENV_FILES := $(foreach f,$(ENV_FILE) $(LOCAL_ENV_FILE),$(if $(wildcard $(f)),--env-file $(f)))

DC   := docker compose $(ENV_FILES) -f $(COMPOSE_FILE)
EXEC := $(DC) exec -T $(SERVICE)

# Extra flags for the tool being wrapped, e.g.
#   make phpunit ARGS="--filter ImportTournamentCommandTest"
ARGS ?=

.DEFAULT_GOAL := help

.PHONY: help up down build restart logs ps shell console composer install \
	tailwind tailwind-watch db-create db-drop seed db-reset setup phpunit test \
	coverage cs cs-fix phpstan check running wait-healthy seed-if-empty \
	dev-stack-only changelog release release-gate deploy rollback deploy-preflight verify-deploy \
	prod-logs prod-version versions not-production

help: ## List the available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-16s\033[0m %s\n", $$1, $$2}'

## --- Stack -----------------------------------------------------------------

setup: up wait-healthy tailwind seed-if-empty ## Bootstrap a fresh clone: stack, stylesheet, database

up: not-production ## Start the dev stack in the background
	$(DC) up -d --build

down: not-production ## Stop the dev stack
	$(DC) down

build: not-production ## Rebuild the dev image
	$(DC) build

restart: down up ## Restart the dev stack

logs: ## Follow the container logs
	$(DC) logs -f $(SERVICE)

ps: ## Show the stack status
	$(DC) ps

shell: ## Open an interactive shell in the php container
	$(DC) exec $(SERVICE) sh

## --- Tooling ---------------------------------------------------------------

console: running ## Run bin/console, e.g. make console ARGS="debug:router"
	$(EXEC) php bin/console $(ARGS)

composer: running ## Run Composer, e.g. make composer ARGS="require --dev phpstan/phpstan"
	$(EXEC) composer $(ARGS)

install: running ## Install the PHP dependencies
	$(EXEC) composer install

## --- Assets ----------------------------------------------------------------

tailwind: running ## Rebuild the Tailwind stylesheet
	$(EXEC) php bin/console tailwind:build $(ARGS)

tailwind-watch: running ## Rebuild the Tailwind stylesheet whenever a template changes
	$(DC) exec $(SERVICE) php bin/console tailwind:build --watch

# Order-only prerequisite: "running" is phony, and a normal prerequisite would
# make this file look stale on every run.
$(TAILWIND_CSS): | running
	$(EXEC) php bin/console tailwind:build

$(CONTAINER_XML): | running
	$(EXEC) php bin/console cache:warmup --env=dev

## --- Database --------------------------------------------------------------

# The schema is not versioned: migrations/ is empty on purpose. A schema change
# is applied by rebuilding the database from the current mapping and replaying
# repeat.sh, which is what db-reset does here and what a deployment does by hand.

db-create: running ## Create the schema from the current entity mapping
	$(EXEC) php bin/console doctrine:schema:create

db-drop: dev-stack-only running ## Drop every table in the database
	$(EXEC) php bin/console doctrine:schema:drop --force --full-database

# -e so a failed replay stops at the offending command rather than carrying on
# and leaving a half-populated database behind. A replay wants an empty schema:
# app:create-season and app:register-payment turn into no-ops against data that
# is already there, but app:import-tournament has no such guard and inserts a
# duplicate tournament every time.
seed: running ## Replay repeat.sh to rebuild the league data
	$(EXEC) sh -e repeat.sh

# Recursive rather than three prerequisites, because prerequisite order is only
# guaranteed for a serial make. Under -j the drop could race the seed.
db-reset: dev-stack-only ## Rebuild the database from repeat.sh
	@$(MAKE) --no-print-directory db-drop
	@$(MAKE) --no-print-directory db-create
	@$(MAKE) --no-print-directory seed

## --- Quality ---------------------------------------------------------------

phpunit: running $(TAILWIND_CSS) ## Run the test suite, e.g. make phpunit ARGS="--filter FooTest"
	$(EXEC) php vendor/bin/phpunit $(ARGS)

test: phpunit ## Alias for phpunit

# The driver is PCOV, which the dev image ships alongside Xdebug and which
# 20-app.dev.ini leaves disabled, so only this target pays for it. Xdebug can
# measure coverage as well, and used to, but it instruments every opcode: the
# same suite takes 45s under Xdebug and 18s under PCOV, for line rates within
# 0.2pp of each other.
#
# XDEBUG_MODE is "off" for the whole stack, which is what lets PHPUnit pick PCOV
# up; a mode with "coverage" in it would put Xdebug back in charge and undo this.
# PCOV only measures lines -- it has no branch or path coverage -- which is what
# the reports were already limited to.
#
# CI reads the Cobertura report to build the job summary, the HTML one is what
# says which lines are missed, and the text one lands in the log.
coverage: running $(TAILWIND_CSS) ## Run the test suite and write the coverage reports to var/coverage
	$(EXEC) php -d memory_limit=256M -d pcov.enabled=1 vendor/bin/phpunit \
		--coverage-text \
		--coverage-cobertura $(COVERAGE_DIR)/cobertura.xml \
		--coverage-html $(COVERAGE_DIR)/html \
		$(ARGS)

cs: running ## Check the code style without writing anything
	$(EXEC) php vendor/bin/php-cs-fixer fix --dry-run --diff $(ARGS)

cs-fix: running ## Apply the code style fixes
	$(EXEC) php vendor/bin/php-cs-fixer fix $(ARGS)

# The Symfony extension reads the compiled dev container to resolve services
# and routes, so the cache has to exist before the analysis starts.
phpstan: running $(CONTAINER_XML) ## Run the static analyser
	$(EXEC) php vendor/bin/phpstan analyse --memory-limit=$(PHPSTAN_MEMORY) $(ARGS)

check: cs phpstan phpunit ## Run every quality gate

## --- Internal --------------------------------------------------------------

running:
	@$(DC) ps --services --status running 2>/dev/null | grep -qx '$(SERVICE)' || { \
		echo 'The "$(SERVICE)" service is not running. Start it with: make up'; \
		exit 1; \
	}

# "running" is not enough for the first boot of a fresh clone: the entrypoint is
# still running composer install into the bind mount, and a second writer there
# corrupts vendor/. The container id comes from Compose, so the raw docker call
# cannot reach a stack this Makefile does not own.
wait-healthy:
	@printf 'Waiting for the "%s" service to become healthy' '$(SERVICE)'; \
	waited=0; \
	while [ $$waited -lt $(HEALTH_TIMEOUT) ]; do \
		cid=$$($(DC) ps -q $(SERVICE) 2>/dev/null); \
		if [ -n "$$cid" ]; then \
			status=$$(docker inspect \
				-f '{{if .State.Health}}{{.State.Health.Status}}{{else}}healthy{{end}}' \
				"$$cid" 2>/dev/null); \
			if [ "$$status" = 'healthy' ]; then echo ' ready.'; exit 0; fi; \
			if [ "$$status" = 'unhealthy' ]; then \
				echo; \
				echo 'The "$(SERVICE)" service is unhealthy. Check: make logs'; \
				exit 1; \
			fi; \
		fi; \
		printf '.'; \
		sleep 2; \
		waited=$$((waited + 2)); \
	done; \
	echo; \
	echo 'Gave up after $(HEALTH_TIMEOUT)s. Check: make logs'; \
	exit 1

# Populates a fresh clone without ever discarding a database that already has a
# schema, so "make setup" stays safe to re-run.
seed-if-empty:
	@if $(EXEC) php bin/console dbal:run-sql -q 'SELECT 1 FROM seasons LIMIT 1' >/dev/null 2>&1; then \
		echo 'The schema already exists, so the database was left alone.'; \
		echo 'Rebuild it from repeat.sh with: make db-reset'; \
	else \
		$(MAKE) db-create && $(MAKE) seed || { \
			echo 'Seeding failed, so the schema exists but holds no data.'; \
			echo 'Fix the cause, then rebuild with: make db-reset'; \
			exit 1; \
		}; \
	fi

# Compose keys a project on the *directory name*, so a dev stack and a
# production stack started from the same checkout are the same project: `make
# up` there does not run alongside production, it recreates production's
# container with dev config -- and the Cloudflare tunnel goes on pointing at it,
# so the live domain starts serving the dev app.
#
# A worktree has its own directory, therefore its own project, and cannot
# collide. That is why local work belongs in one.
#
# The tell is structural rather than a naming convention: the dev override
# bind-mounts the working copy at /app, and the production image has no such
# mount.
not-production:
	@cid=$$($(DC) ps -q $(SERVICE) 2>/dev/null); \
	if [ -n "$$cid" ] && ! docker inspect "$$cid" \
		--format '{{range .Mounts}}{{println .Destination}}{{end}}' \
		2>/dev/null | grep -qx '/app'; then \
		echo 'A production container is running in this directory.'; \
		echo; \
		echo 'The dev stack shares its Compose project, so this would replace the'; \
		echo 'live site rather than start something beside it.'; \
		echo; \
		echo 'Do local work in a git worktree:'; \
		echo '  git worktree add .claude/worktrees/<name> -b <branch>'; \
		echo 'See AGENTS.md, "Where to run the dev stack".'; \
		exit 1; \
	fi

# Every target is scoped to the dev Compose file, and a production stack is a
# different Compose project started from compose.yaml. Refuse outright rather
# than drop the tables of whatever COMPOSE_FILE has been pointed at.
dev-stack-only:
	@case '$(COMPOSE_FILE)' in \
		*compose.override.yaml) ;; \
		*) echo 'Refusing to run a destructive target against "$(COMPOSE_FILE)".'; \
		   echo 'The database targets are for the dev stack only.'; \
		   exit 1;; \
	esac

## --- Release ---------------------------------------------------------------

# A release is a git tag, and a tag is the only thing that publishes an image.
# The version is recorded nowhere else -- no VERSION file to forget to bump, and
# no way to build something by hand and call it 1.2.3. The release workflow
# reacts to the pushed tag, builds the production image from that exact commit
# and pushes it to the registry below; "deploy" then pulls what CI built.
VERSION ?=

# Where the release workflow pushes and where "deploy" pulls from. Overridable
# so a fork can point somewhere else without editing compose.yaml.
APP_IMAGE ?= ghcr.io/mcutajar/beybladexmalta

# Everything the published image deliberately does not carry. It is built by CI
# from a checkout with no .env.local, so these have to come from this host at
# run time -- see the environment block in compose.yaml.
REQUIRED_PROD_VARS := APP_SECRET DATABASE_URL PAYMENTS_ADMIN_PASSPHRASE \
	TOURNAMENTS_ADMIN_PASSPHRASE

# The generated changelog, and the pinned image that generates it. git-cliff is
# not a PHP tool, so it has no place in the dev container, and the one rule
# still holds either way: nothing is installed on the host. The version here
# and the one the release workflow pins are the same on purpose -- both render
# the same file from the same cliff.toml, and only stay identical if they are
# the same git-cliff.
CHANGELOG       ?= CHANGELOG.md
GIT_CLIFF_IMAGE ?= orhunp/git-cliff:2.13.1

# Semantic versioning, without the leading "v" -- the tag gets the prefix, the
# image tag does not. A pre-release suffix is allowed; build metadata is not,
# because "+" is not legal in a Docker tag.
SEMVER_PATTERN := ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-[0-9A-Za-z.-]+)?$$

# Renders the whole file, so it is a product of the history rather than a thing
# anyone appends to. Naming a VERSION renders the commits since the last tag
# under that version instead of leaving them out, which is what "make release"
# wants and what a preview of the next release looks like.
#
# Both the working tree and the git directory are mounted, at their real host
# paths. In a worktree ".git" is a file holding an absolute path into the main
# checkout, and that path resolves inside the container only if it is the same
# path outside it; in the main checkout the second mount is the first one's
# ".git" and changes nothing. --user keeps the rendered file owned by whoever
# ran make rather than by root.
changelog: ## Rewrite CHANGELOG.md from the commits, e.g. make changelog VERSION=1.1.0
	@set -e; \
	top=$$(git rev-parse --show-toplevel); \
	common=$$(cd "$$(git rev-parse --git-common-dir)" && pwd); \
	docker run --rm --user "$$(id -u):$$(id -g)" \
		-v "$$top:$$top" -v "$$common:$$common" -w "$$top" \
		$(GIT_CLIFF_IMAGE) --config cliff.toml \
		$(if $(VERSION),--tag "v$(VERSION)") --output "$(CHANGELOG)"

# Refuses anything that would produce a release nobody can reproduce: a version
# that is not semver, a tree that is not exactly origin/main, a version already
# used. No dev stack is started, so this is safe in the main checkout beside a
# running production container -- which is also the only place it *can* run:
# git will not check out main in a worktree while the main checkout holds it.
#
# The commit is the check, not the branch name. What has to be true is that the
# tag points at origin/main; whether the local ref is called "main" is a proxy
# for that, and a worse one.
#
# The changelog is rendered, pushed to main and only then tagged, so the tag's
# own tree contains the entry describing it. That ordering is why this is not a
# step in the release workflow: a workflow push happens after the tag exists,
# and would have to reach main with a token that cannot satisfy main's required
# status check -- GITHUB_TOKEN pushes do not trigger workflows, so the check it
# needs would never report. The cost is that the tagged commit is one past the
# one release-gate read CI's verdict for. It differs by a generated markdown
# file, and the release workflow runs the suite on the tag before it publishes
# anything.
release: ## Tag a release and let CI publish the image, e.g. make release VERSION=1.1.0
	@set -e; \
	version='$(VERSION)'; \
	if [ -z "$$version" ]; then \
		echo 'Name the version: make release VERSION=1.1.0'; \
		echo 'The current releases are: make versions'; \
		exit 1; \
	fi; \
	case "$$version" in v*) \
		echo "Drop the \"v\": make release VERSION=$${version#v}"; \
		echo 'The tag gets the prefix, the version does not.'; \
		exit 1;; \
	esac; \
	if ! printf '%s' "$$version" | grep -qE '$(SEMVER_PATTERN)'; then \
		echo "\"$$version\" is not a semantic version."; \
		echo 'Expected MAJOR.MINOR.PATCH, e.g. 1.1.0 or 2.0.0-rc.1.'; \
		exit 1; \
	fi; \
	if [ -n "$$(git status --porcelain)" ]; then \
		echo 'The working tree has uncommitted changes.'; \
		echo 'A release must name a commit that is pushed, or nobody can rebuild it.'; \
		exit 1; \
	fi; \
	git fetch --quiet origin main; \
	if [ "$$(git rev-parse HEAD)" != "$$(git rev-parse origin/main)" ]; then \
		echo 'HEAD is not origin/main, so this would tag something else.'; \
		echo "  HEAD:        $$(git rev-parse --short HEAD)"; \
		echo "  origin/main: $$(git rev-parse --short origin/main)"; \
		echo 'Releases are cut from main. Check it out, or pull, and try again.'; \
		exit 1; \
	fi; \
	if git rev-parse -q --verify "refs/tags/v$$version" >/dev/null \
		|| [ -n "$$(git ls-remote --tags origin "refs/tags/v$$version")" ]; then \
		echo "v$$version already exists. Versions are never reused."; \
		exit 1; \
	fi; \
	$(MAKE) --no-print-directory release-gate; \
	$(MAKE) --no-print-directory changelog VERSION="$$version"; \
	if [ -n "$$(git status --porcelain -- $(CHANGELOG))" ]; then \
		git add -- $(CHANGELOG); \
		git commit --quiet -m "chore(release): v$$version"; \
		git push --quiet origin HEAD:main; \
		echo "Wrote $(CHANGELOG) for v$$version and pushed it to main."; \
	fi; \
	git tag -a "v$$version" -m "Release $$version"; \
	git push origin "v$$version"; \
	echo; \
	echo "Pushed v$$version. The release workflow is now building"; \
	echo "  $(APP_IMAGE):$$version"; \
	echo 'Watch it with: gh run watch'; \
	echo "Then deploy: make deploy VERSION=$$version"

# What "make check" would have done here, asked of the run that is actually
# authoritative. The commit being tagged is origin/main, which CI has already
# tested on push -- re-running the suite locally would test the same commit a
# second time, and would need a dev stack in a directory that must not have one.
#
# The cost this avoids is specific: a version number is spent the moment it is
# pushed, so tagging a commit whose tests fail leaves a tag with no image behind
# it and no way back except the next number. A broken or absent gh is not a
# reason to block a release, so that case warns and carries on.
release-gate:
	@set -e; \
	sha=$$(git rev-parse HEAD); \
	state=$$(gh run list --commit "$$sha" --workflow ci.yaml --limit 1 \
		--json status,conclusion \
		--jq 'map(.status + "/" + (.conclusion // "none")) | first // "missing"' \
		2>/dev/null || echo 'unknown'); \
	case "$$state" in \
		completed/success) \
			echo "CI is green on $$(git rev-parse --short HEAD)."; ;; \
		missing|unknown|'') \
			echo "No CI verdict found for $$(git rev-parse --short HEAD)."; \
			echo 'The release workflow will be the first thing to test it.'; ;; \
		completed/*) \
			echo "CI on this commit finished as \"$${state#completed/}\"."; \
			echo 'Tagging it would spend a version number on a build that cannot pass.'; \
			exit 1; ;; \
		*) \
			echo 'CI is still running on this commit.'; \
			echo 'Wait for it -- gh run watch -- then release.'; \
			exit 1; ;; \
	esac

versions: ## List the released versions, marking the one production is running
	@set -e; \
	live=''; \
	cid=$$($(DC_PROD) ps -q $(SERVICE) 2>/dev/null || true); \
	if [ -n "$$cid" ]; then \
		live=$$(docker inspect --format \
			'{{index .Config.Labels "org.opencontainers.image.version"}}' \
			"$$cid" 2>/dev/null || true); \
	fi; \
	tags=$$(git tag -l 'v*' --sort=-v:refname); \
	if [ -z "$$tags" ]; then \
		echo 'No releases yet. Cut the first with: make release VERSION=1.0.0'; \
		exit 0; \
	fi; \
	for tag in $$tags; do \
		version=$${tag#v}; \
		if [ "$$version" = "$$live" ]; then \
			echo "  $$version  <- live"; \
		else \
			echo "  $$version"; \
		fi; \
	done

## --- Deploy ----------------------------------------------------------------

# Production is a separate Compose project built from compose.yaml alone.
# Plain "docker compose" in this checkout also reads compose.override.yaml,
# which builds the *dev* target and bind-mounts the working copy over /app --
# so every production command names its file explicitly. Getting this wrong
# during an incident is how a rebuild silently produced a dev image.
PROD_COMPOSE_FILE ?= compose.yaml
DC_PROD := docker compose $(ENV_FILES) -f $(PROD_COMPOSE_FILE)

# Set by "deploy" so "verify-deploy" can assert that what came up is what was
# asked for. Empty when verify-deploy is run on its own, which only reports.
EXPECT_VERSION ?=

# Nothing is built here. The image was built and tested by CI from the tagged
# commit, so a deploy is a pull and a restart -- which is also why a rollback
# costs the same as a deploy and needs no source checkout at all.
deploy: ## Deploy a released version, e.g. make deploy VERSION=1.0.0
	@set -e; \
	version='$(VERSION)'; \
	if [ -z "$$version" ]; then \
		version=$$(git describe --tags --exact-match 2>/dev/null | sed 's/^v//' || true); \
	fi; \
	if [ -z "$$version" ]; then \
		echo 'Name the version to deploy: make deploy VERSION=1.0.0'; \
		echo 'HEAD carries no release tag to fall back on.'; \
		echo 'The published versions are: make versions'; \
		exit 1; \
	fi; \
	$(MAKE) --no-print-directory deploy-preflight; \
	echo "Deploying $(APP_IMAGE):$$version"; \
	APP_VERSION="$$version" $(DC_PROD) pull $(SERVICE); \
	APP_VERSION="$$version" $(DC_PROD) up -d --force-recreate $(SERVICE); \
	$(MAKE) --no-print-directory wait-healthy COMPOSE_FILE=$(PROD_COMPOSE_FILE); \
	$(MAKE) --no-print-directory verify-deploy EXPECT_VERSION="$$version"

# Deploying an earlier version is the whole rollback procedure, because the
# image for it still exists in the registry and nothing rebuilds it. Named
# separately so it is in "make help" when somebody needs it in a hurry.
rollback: ## Put production back on an earlier version, e.g. make rollback VERSION=1.0.0
	@set -e; \
	if [ -z '$(VERSION)' ]; then \
		echo 'Name the version to go back to: make rollback VERSION=1.0.0'; \
		echo 'The published versions are: make versions'; \
		exit 1; \
	fi; \
	$(MAKE) --no-print-directory deploy VERSION='$(VERSION)'

# The published image carries the committed .env defaults and nothing else, so
# an unset passphrase here does not fail loudly -- it starts a site whose admin
# forms compare against an empty string. Checked before anything is pulled or
# recreated, and reported by name: no value is ever printed.
deploy-preflight: ## Check this host supplies the secrets the image does not carry
	@set -e; \
	missing=''; \
	for var in $(REQUIRED_PROD_VARS); do \
		value=''; \
		for f in $(ENV_FILE) $(LOCAL_ENV_FILE); do \
			[ -f "$$f" ] || continue; \
			line=$$(grep -E "^[[:space:]]*$$var=" "$$f" | tail -1 || true); \
			if [ -n "$$line" ]; then value=$${line#*=}; fi; \
		done; \
		from_env=$$(printenv "$$var" 2>/dev/null || true); \
		if [ -n "$$from_env" ]; then value="$$from_env"; fi; \
		if [ -z "$$(printf '%s' "$$value" | tr -d '[:space:]')" ]; then \
			missing="$$missing $$var"; \
		fi; \
	done; \
	if [ -n "$$missing" ]; then \
		echo 'Production would start without values it needs:'; \
		for var in $$missing; do echo "  $$var"; done; \
		echo; \
		echo 'The published image is built by CI and carries no secrets, so these'; \
		echo 'are read from this host. Set them in $(LOCAL_ENV_FILE) and try again.'; \
		exit 1; \
	fi; \
	echo 'Every value production needs at run time is set.'

prod-logs: ## Tail the production container's logs
	$(DC_PROD) logs -f --tail=100 $(SERVICE)

prod-version: ## Print the version production is running
	@set -e; \
	cid=$$($(DC_PROD) ps -q $(SERVICE) 2>/dev/null || true); \
	if [ -z "$$cid" ]; then \
		echo 'The production "$(SERVICE)" service is not running.'; \
		exit 1; \
	fi; \
	docker inspect --format \
		'{{index .Config.Labels "org.opencontainers.image.version"}}' "$$cid"

# Three things a deploy can get wrong without the site going down, all of which
# have happened or are now possible:
#
#   1. The image ships a package the code needs but composer never installed,
#      so the kernel cannot boot. A stale cache can hide this until something
#      forces a rebuild, at which point the site 502s.
#   2. A compiled cache outlives the code it was compiled from. Symfony never
#      revalidates the container, routes or Twig in prod, so the site keeps
#      serving an older build and every release looks like a no-op.
#   3. The container that came up is not the version that was asked for --
#      compose reused an existing one, or something local was tagged by hand.
#
# "about" boots the kernel, which fails loudly on the first; comparing mtimes
# catches the second; the version label catches the third. That label is set
# only by the release workflow, so an image built anywhere else reads
# "0.0.0-dev" and cannot pass for a release.
verify-deploy: ## Assert production booted the version it was given and compiled it fresh
	@set -e; \
	cid=$$($(DC_PROD) ps -q $(SERVICE)); \
	if [ -z "$$cid" ]; then \
		echo 'The production "$(SERVICE)" service is not running.'; exit 1; \
	fi; \
	if ! docker exec "$$cid" php bin/console about --env=prod >/dev/null 2>&1; then \
		echo 'The kernel does not boot in production.'; \
		echo 'Usually a package in composer.json that the image never installed.'; \
		echo 'Look at: make prod-logs'; \
		exit 1; \
	fi; \
	running=$$(docker inspect --format \
		'{{index .Config.Labels "org.opencontainers.image.version"}}' \
		"$$cid" 2>/dev/null || true); \
	if [ -n '$(EXPECT_VERSION)' ] && [ "$$running" != '$(EXPECT_VERSION)' ]; then \
		echo "Production is running \"$$running\", not \"$(EXPECT_VERSION)\"."; \
		echo 'The container that came up is not the image this deploy pulled.'; \
		echo 'Look at: make prod-logs'; \
		exit 1; \
	fi; \
	docker exec "$$cid" sh -c '\
		if [ ! -d /app/var/cache/prod ]; then exit 0; fi; \
		stale=$$(find /app/src /app/config /app/templates -type f \
			-newer /app/var/cache/prod -print -quit 2>/dev/null); \
		if [ -n "$$stale" ]; then \
			echo "Compiled cache is older than the code it should have been built from,"; \
			echo "starting with: $$stale"; \
			echo "Production is serving a different build to the one in this image."; \
			exit 1; \
		fi' || exit 1; \
	echo "Production is running $${running:-an unlabelled image} and compiled it fresh."
