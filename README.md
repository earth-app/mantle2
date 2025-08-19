# Mantle2 Backend

A modern Drupal 11 application built with best practices for development, testing, and deployment.

## Prerequisites

- PHP 8.3+
- Composer 2.x
- Node.js 18+ (for frontend assets)
- DDEV (for local development)

## Quick Start

### Local Development with DDEV

1. **Clone the repository:**
   ```bash
   git clone [repository-url]
   cd mantle2
   ```

2. **Start DDEV:**
   ```bash
   ddev start
   ```

3. **Install dependencies:**
   ```bash
   ddev composer install
   ```

4. **Install Drupal:**
   ```bash
   ddev drush site:install --yes
   ```

5. **Enable custom modules:**
   ```bash
   ddev drush en mantle_core -y
   ```

### Manual Setup (without DDEV)

1. **Install PHP dependencies:**
   ```bash
   composer install
   ```

2. **Configure your web server** to point to the `web/` directory

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