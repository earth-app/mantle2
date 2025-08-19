# Mantle2 Backend

A modern Drupal 11 backend with exact API parity to the original Mantle API, built with Docker Compose for local development using Postgres, Redis, and MinIO.

## Overview

Mantle2 provides:
- **Exact API Parity**: Identical JSON responses and HTTP semantics to the original Mantle API
- **Modern Infrastructure**: Docker Compose with Postgres, Redis, and MinIO for local development
- **AI Integration**: Configurable AI providers via Drupal AI module (OpenRouter, OpenAI, Anthropic, etc.)
- **Queue System**: Internal background job processing for "Cloud" functionality
- **Contract Testing**: Automated validation against OpenAPI specification
- **Quality Assurance**: PHPStan, coding standards, and comprehensive testing

## Prerequisites

- Docker & Docker Compose
- Git

## Quick Start

### Automated Setup (Recommended)

```bash
git clone [repository-url]
cd mantle2
./setup.sh
```

This will:
- Start all Docker services (Nginx, PHP, Postgres, Redis, MinIO)
- Install Composer dependencies
- Fetch OpenAPI specification
- Install and configure Drupal
- Enable required modules

### Manual Setup

1. **Start services:**
   ```bash
   make up
   ```

2. **Install dependencies:**
   ```bash
   make composer-install
   ```

3. **Install Drupal:**
   ```bash
   make install-drupal
   ```

4. **Enable modules:**
   ```bash
   make enable-modules
   ```

## Services

After setup, the following services are available:

- **Web Application**: http://localhost:8080
- **MinIO Console**: http://localhost:9001 (minio/miniosecret)
- **Postgres**: localhost:5432 (earth/earth)
- **Redis**: localhost:6379

## API Parity

The API provides exact compatibility with the original Mantle API:

- **Health Check**: `GET /api/health`
- **OpenAPI Spec**: `GET /openapi`
- **Authentication**: `/api/auth/*` endpoints
- **User Management**: `/api/users/*` endpoints
- **Content APIs**: `/api/prompts`, `/api/activities`, `/api/articles`, `/api/events`

### Contract Testing

Run contract tests to validate API parity:

```bash
make test-contract
```

This fetches the latest OpenAPI specification from https://api.earth-app.com/openapi and validates all endpoints.

3. **Set up your database** and update `web/sites/default/settings.php`

4. **Install Drupal:**
   ```bash
   vendor/bin/drush site:install --yes
   ```

## Development Workflow

### Code Quality Tools

The project includes several code quality tools:

- **PHPStan** - Static analysis tool
- **PHP_CodeSniffer** - Code style checker
- **PHPUnit** - Unit testing framework

### Available Commands

```bash
# Run code style checks
composer lint

# Fix code style issues automatically
composer lint-fix

# Run static analysis
composer analyze

# Run unit tests
composer test
```

### DDEV Commands

```bash
# Access the web container
ddev ssh

# Run Drush commands
ddev drush [command]

# View logs
ddev logs

# Import/export database
ddev import-db < database.sql
ddev export-db > database.sql
```

## Project Structure

```
├── .ddev/                  # DDEV configuration
├── web/                    # Document root
│   ├── core/              # Drupal core
│   ├── modules/
│   │   └── custom/        # Custom modules
│   │       └── mantle_core/
│   ├── themes/
│   │   └── custom/        # Custom themes
│   │       └── mantle_theme/
│   └── sites/default/     # Site configuration
├── vendor/                # Composer dependencies
├── composer.json          # PHP dependencies
├── phpcs.xml              # Code style configuration
├── phpstan.neon           # Static analysis configuration
└── README.md              # This file
```

## Custom Modules

### Mantle Core
The core module (`mantle_core`) provides:
- Base functionality for the application
- Service definitions
- Core business logic

## Custom Theme

### Mantle Theme
A custom theme (`mantle_theme`) that extends Olivero:
- Custom styling
- JavaScript enhancements
- Template overrides

## Testing

### Unit Tests
```bash
# Run all unit tests
composer test

# Run tests for specific module
vendor/bin/phpunit web/modules/custom/mantle_core/tests/
```

### Code Coverage
```bash
# Generate code coverage report
vendor/bin/phpunit --coverage-html coverage/
```

## Deployment

### Production Checklist

1. **Update composer.json** for production dependencies:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. **Clear all caches:**
   ```bash
   drush cache:rebuild
   ```

3. **Update database:**
   ```bash
   drush updatedb
   ```

4. **Import configuration:**
   ```bash
   drush config:import
   ```

## Environment Configuration

### Local Development
- Caching disabled
- Debug mode enabled
- Error reporting verbose
- CSS/JS aggregation disabled

### Production
- All caches enabled
- Debug mode disabled
- Error logging only
- CSS/JS aggregation enabled

## Contributing

1. **Create a feature branch:**
   ```bash
   git checkout -b feature/description
   ```

2. **Run code quality checks:**
   ```bash
   composer lint
   composer analyze
   composer test
   ```

3. **Commit changes** following conventional commit format

4. **Create a pull request**

## Support

For questions and issues, please check:
- [Drupal Documentation](https://www.drupal.org/docs)
- [DDEV Documentation](https://ddev.readthedocs.io/)
- Project issue tracker

## License

This project is licensed under GPL-2.0-or-later.