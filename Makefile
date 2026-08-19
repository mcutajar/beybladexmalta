# Every target runs inside the dev container.
# Nothing here requires PHP, Composer or Postgres on the host machine.

COMPOSE_FILE ?= compose.override.yaml
ENV_FILE     ?= .env.local
SERVICE      ?= php

# Build artifact, not committed. Targets that render templates depend on it,
# so a fresh clone builds it once instead of failing with an AssetMapper error.
TAILWIND_CSS := var/tailwind/app.built.css

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

## --- Quality ---------------------------------------------------------------

phpunit: running $(TAILWIND_CSS) ## Run the test suite, e.g. make phpunit ARGS="--filter FooTest"
	$(EXEC) php vendor/bin/phpunit $(ARGS)

test: phpunit ## Alias for phpunit

cs: running ## Check the code style without writing anything
	$(EXEC) php vendor/bin/php-cs-fixer fix --dry-run --diff $(ARGS)

cs-fix: running ## Apply the code style fixes
	$(EXEC) php vendor/bin/php-cs-fixer fix $(ARGS)

phpstan: running ## Run the static analyser
	@$(EXEC) test -x vendor/bin/phpstan || { \
		echo 'PHPStan is not installed yet. Add it with:'; \
		echo '  make composer ARGS="require --dev phpstan/phpstan phpstan/phpstan-symfony"'; \
		exit 1; \
	}
	$(EXEC) php vendor/bin/phpstan analyse $(ARGS)

# Add phpstan to this list once it is installed.
check: cs phpunit ## Run every quality gate

## --- Internal --------------------------------------------------------------

running:
	@$(DC) ps --services --status running 2>/dev/null | grep -qx '$(SERVICE)' || { \
		echo 'The "$(SERVICE)" service is not running. Start it with: make up'; \
		exit 1; \
	}
