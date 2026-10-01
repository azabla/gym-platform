# Short, memorable commands for everyday work. Run `make` to list them.
# (Windows: run these inside WSL2.)

# Pass your host user/group IDs to the image build (see docker/php/Dockerfile).
export UID := $(shell id -u)
export GID := $(shell id -g)

DC   = docker compose
EXEC = $(DC) exec app

.DEFAULT_GOAL := help
.PHONY: help build up down restart workers ps logs shell artisan composer test lint fix analyse check fresh bucket psql

help: ## List available commands
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-10s\033[0m %s\n", $$1, $$2}'

build: ## Build the PHP image
	$(DC) build

up: ## Start all services in the background
	$(DC) up -d

down: ## Stop all services (data is kept)
	$(DC) down

restart: ## Restart all services
	$(DC) restart

workers: ## Restart queue + scheduler (needed after code changes)
	$(DC) restart queue scheduler

ps: ## Show service status and health
	$(DC) ps

logs: ## Follow logs of all services
	$(DC) logs -f

shell: ## Open a bash shell in the app container
	$(EXEC) bash

artisan: ## Run artisan, e.g. make artisan c="migrate"
	$(EXEC) php artisan $(c)

composer: ## Run composer, e.g. make composer c="require foo/bar"
	$(EXEC) composer $(c)

test: ## Run the test suite
	$(EXEC) php artisan test

lint: ## Check code style (no changes)
	$(EXEC) ./vendor/bin/pint --test

fix: ## Fix code style
	$(EXEC) ./vendor/bin/pint

analyse: ## Static analysis with Larastan
	$(EXEC) ./vendor/bin/phpstan analyse --memory-limit=1G

check: lint analyse test ## Everything CI will run

fresh: ## Drop and re-run all migrations with seeders
	$(EXEC) php artisan migrate:fresh --seed

bucket: ## Create the local S3 bucket (once)
	echo "s3.bucket.create -name gym-local" | $(DC) exec -T seaweedfs weed shell -master=localhost:9333

psql: ## Open psql on the development database
	$(DC) exec postgres psql -U gym -d gym
