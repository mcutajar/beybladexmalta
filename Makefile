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
	dev-stack-only deploy verify-deploy prod-logs not-production

help: ## List the available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

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

# The driver is Xdebug, which the dev image already ships and which
# compose.override.yaml already puts in coverage mode, so there is nothing to
# install and nothing to pass. Setting XDEBUG_MODE to anything without
# "coverage" in it turns the reports into empty ones, with a PHPUnit warning
# rather than a failure.
#
# CI reads the Cobertura report to build the pull request comment, the HTML one
# is what says which lines are missed, and the text one lands in the log.
coverage: running $(TAILWIND_CSS) ## Run the test suite and write the coverage reports to var/coverage
	$(EXEC) php vendor/bin/phpunit \
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

## --- Deploy ----------------------------------------------------------------

# Production is a separate Compose project built from compose.yaml alone.
# Plain "docker compose" in this checkout also reads compose.override.yaml,
# which builds the *dev* target and bind-mounts the working copy over /app --
# so every production command names its file explicitly. Getting this wrong
# during an incident is how a rebuild silently produced a dev image.
PROD_COMPOSE_FILE ?= compose.yaml
DC_PROD := docker compose $(ENV_FILES) -f $(PROD_COMPOSE_FILE)

deploy: ## Rebuild and restart production, then prove it is serving this code
	$(DC_PROD) up -d --build --force-recreate $(SERVICE)
	@$(MAKE) --no-print-directory wait-healthy COMPOSE_FILE=$(PROD_COMPOSE_FILE)
	@$(MAKE) --no-print-directory verify-deploy

prod-logs: ## Tail the production container's logs
	$(DC_PROD) logs -f --tail=100 $(SERVICE)

# Two things a deploy can get wrong without the site going down, both of which
# have happened:
#
#   1. The image ships a package the code needs but composer never installed,
#      so the kernel cannot boot. A stale cache can hide this until something
#      forces a rebuild, at which point the site 502s.
#   2. A compiled cache outlives the code it was compiled from. Symfony never
#      revalidates the container, routes or Twig in prod, so the site keeps
#      serving an older build and every release looks like a no-op.
#
# "about" boots the kernel, which fails loudly on the first; comparing mtimes
# catches the second.
verify-deploy: ## Assert production booted this build and compiled it fresh
	@cid=$$($(DC_PROD) ps -q $(SERVICE)); \
	if [ -z "$$cid" ]; then \
		echo 'The production "$(SERVICE)" service is not running.'; exit 1; \
	fi; \
	if ! docker exec "$$cid" php bin/console about --env=prod >/dev/null 2>&1; then \
		echo 'The kernel does not boot in production.'; \
		echo 'Usually a package in composer.json that the image never installed.'; \
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
			echo "Production is serving a different build to the one in this checkout."; \
			exit 1; \
		fi' || exit 1; \
	echo 'Production booted this build and compiled it fresh.'
