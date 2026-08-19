# Every target runs inside the dev container.
# Nothing here requires PHP, Composer or Postgres on the host machine.

COMPOSE_FILE ?= compose.override.yaml
ENV_FILE     ?= .env.local
SERVICE      ?= php

# Build artifact, not committed. Targets that render templates depend on it,
# so a fresh clone builds it once instead of failing with an AssetMapper error.
TAILWIND_CSS := var/tailwind/app.built.css

# The compiled dev container, which phpstan-symfony reads to resolve services
# and routes. Also a build artifact, also not committed.
CONTAINER_XML := var/cache/dev/App_KernelDevDebugContainer.xml

# PHPStan needs more than the 128M the container's php.ini gives CLI scripts.
PHPSTAN_MEMORY ?= 1G

DC   := docker compose $(if $(wildcard $(ENV_FILE)),--env-file $(ENV_FILE),) -f $(COMPOSE_FILE)
EXEC := $(DC) exec -T $(SERVICE)

# Extra flags for the tool being wrapped, e.g.
#   make phpunit ARGS="--filter ImportTournamentCommandTest"
ARGS ?=

.DEFAULT_GOAL := help

.PHONY: help up down build restart logs ps shell console composer install \
	tailwind tailwind-watch phpunit test cs cs-fix phpstan check running

help: ## List the available targets
	@grep -hE '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) \
		| awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

## --- Stack -----------------------------------------------------------------

up: ## Start the dev stack in the background
	$(DC) up -d --build

down: ## Stop the dev stack
	$(DC) down

build: ## Rebuild the dev image
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

## --- Quality ---------------------------------------------------------------

phpunit: running $(TAILWIND_CSS) ## Run the test suite, e.g. make phpunit ARGS="--filter FooTest"
	$(EXEC) php vendor/bin/phpunit $(ARGS)

test: phpunit ## Alias for phpunit

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
