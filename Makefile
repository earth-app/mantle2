# Mantle2 Development Makefile

.PHONY: help install start stop restart status logs ssh lint lint-fix analyze test clean up down build-containers

# Default target
help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# Setup and installation
install: ## Install dependencies and setup the project
	./setup.sh

# Docker Compose commands
up: ## Start all services using Docker Compose
	docker-compose up -d

down: ## Stop all services using Docker Compose
	docker-compose down

start: up ## Start the development environment (alias for up)

stop: down ## Stop the development environment (alias for down)

restart: ## Restart the development environment
	docker-compose restart

status: ## Show development environment status
	docker-compose ps

logs: ## Show logs
	docker-compose logs -f

logs-php: ## Show PHP logs
	docker-compose logs -f php

logs-nginx: ## Show Nginx logs
	docker-compose logs -f nginx

build-containers: ## Build Docker containers
	docker-compose build

# Container access
ssh: ## Access the PHP container
	docker-compose exec php bash

ssh-nginx: ## Access the Nginx container
	docker-compose exec nginx sh

# Development commands
lint: ## Check code style
	docker-compose exec php composer lint

lint-fix: ## Fix code style issues automatically
	docker-compose exec php composer lint-fix

analyze: ## Run static analysis
	docker-compose exec php composer analyze

test: ## Run unit tests
	docker-compose exec php composer test

# Contract testing
test-contract: ## Run contract tests against OpenAPI spec
	./scripts/fetch-openapi.sh
	docker-compose exec php vendor/bin/phpunit tests/contract/

# Database commands
db-import: ## Import database from file (usage: make db-import FILE=database.sql)
	docker-compose exec -T postgres psql -U earth -d earth < $(FILE)

db-export: ## Export database to file (usage: make db-export FILE=database.sql)
	docker-compose exec postgres pg_dump -U earth earth > $(FILE)

db-drop: ## Drop all database tables
	docker-compose exec php drush sql:drop -y

# Drupal commands
drush: ## Run drush command (usage: make drush CMD="cache:rebuild")
	docker-compose exec php drush $(CMD)

cr: ## Clear Drupal cache
	docker-compose exec php drush cache:rebuild

install-drupal: ## Install Drupal
	docker-compose exec php drush site:install standard --yes --site-name="Mantle2" --account-name=admin --account-pass=admin --db-url=pgsql://earth:earth@postgres/earth

enable-modules: ## Enable custom modules
	docker-compose exec php drush en earth_api redis simple_oauth tfa advancedqueue -y

# AI and queue commands
test-ai: ## Test AI provider integration
	docker-compose exec php drush earth-api:test-ai

run-queues: ## Process background queues
	docker-compose exec php drush advancedqueue:queue:process default

# Cleanup commands
clean: ## Clean up temporary files and caches
	docker-compose down
	docker volume prune -f
	docker system prune -f

# Composer commands
composer-install: ## Install composer dependencies
	docker-compose exec php composer install

composer-update: ## Update composer dependencies
	docker-compose exec php composer update

# Quality assurance
qa: lint analyze test ## Run all quality assurance checks

# Production build
build: ## Build for production
	docker-compose exec php composer install --no-dev --optimize-autoloader
	docker-compose exec php drush cache:rebuild

# Development reset
reset: ## Reset development environment
	make down
	make up
	make cr

# Fetch OpenAPI specification
fetch-openapi: ## Download OpenAPI specification from earth-app.com
	./scripts/fetch-openapi.sh