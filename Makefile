# Mantle2 Development Makefile

.PHONY: help install start stop restart status logs ssh lint lint-fix analyze test clean

# Default target
help: ## Show this help message
	@echo 'Usage: make [target]'
	@echo ''
	@echo 'Targets:'
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  %-15s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

# Setup and installation
install: ## Install dependencies and setup the project
	./setup.sh

# DDEV commands
start: ## Start the development environment
	ddev start

stop: ## Stop the development environment
	ddev stop

restart: ## Restart the development environment
	ddev restart

status: ## Show development environment status
	ddev describe

logs: ## Show logs
	ddev logs

ssh: ## Access the web container
	ddev ssh

# Development commands
lint: ## Check code style
	ddev composer lint

lint-fix: ## Fix code style issues automatically
	ddev composer lint-fix

analyze: ## Run static analysis
	ddev composer analyze

test: ## Run unit tests
	ddev composer test

# Database commands
db-import: ## Import database from file (usage: make db-import FILE=database.sql)
	ddev import-db < $(FILE)

db-export: ## Export database to file (usage: make db-export FILE=database.sql)
	ddev export-db > $(FILE)

db-drop: ## Drop all database tables
	ddev drush sql:drop -y

# Drupal commands
drush: ## Run drush command (usage: make drush CMD="cache:rebuild")
	ddev drush $(CMD)

cr: ## Clear Drupal cache
	ddev drush cache:rebuild

install-drupal: ## Install Drupal
	ddev drush site:install standard --yes --site-name="Mantle2" --account-name=admin --account-pass=admin

enable-modules: ## Enable custom modules
	ddev drush en mantle_core -y

# Cleanup commands
clean: ## Clean up temporary files and caches
	rm -rf web/sites/default/files/php/twig/*
	rm -rf coverage/
	ddev drush cache:rebuild

# Composer commands
composer-install: ## Install composer dependencies
	ddev composer install

composer-update: ## Update composer dependencies
	ddev composer update

# Quality assurance
qa: lint analyze test ## Run all quality assurance checks

# Production build
build: ## Build for production
	composer install --no-dev --optimize-autoloader
	ddev drush cache:rebuild

# Development reset
reset: ## Reset development environment
	ddev stop
	ddev start
	make cr