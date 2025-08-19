# Mantle2 Backend Setup Documentation

This document provides comprehensive setup instructions for the Mantle2 backend system.

## Architecture Overview

Mantle2 is built on Drupal 11 with modern development practices:

- **Framework**: Drupal 11 Core
- **PHP Version**: 8.3+
- **Database**: MySQL 8.0 / MariaDB 10.11
- **Development Environment**: DDEV
- **Code Quality**: PHPStan, PHP_CodeSniffer, PHPUnit
- **Containerization**: Docker with Apache
- **CI/CD**: GitHub Actions

## Prerequisites

### System Requirements

- PHP 8.3 or higher
- Composer 2.x
- Node.js 18+ (for frontend tooling)
- MySQL 8.0 or MariaDB 10.11
- Apache 2.4 or Nginx

### Development Tools

- **DDEV** (recommended for local development)
- **Git** for version control
- **Docker** for containerized deployment

## Installation Methods

### Method 1: DDEV (Recommended)

```bash
# Clone the repository
git clone <repository-url>
cd mantle2

# Run the setup script
./setup.sh

# Or manually:
ddev start
ddev composer install
ddev drush site:install standard --yes --site-name="Mantle2"
ddev drush en mantle_core -y
```

### Method 2: Manual Setup

```bash
# Clone the repository
git clone <repository-url>
cd mantle2

# Install dependencies
composer install

# Configure database in web/sites/default/settings.php
cp web/sites/default/default.settings.php web/sites/default/settings.php

# Install Drupal
vendor/bin/drush site:install standard --yes \
  --db-url="mysql://user:pass@localhost/mantle2" \
  --site-name="Mantle2"

# Enable custom modules
vendor/bin/drush en mantle_core -y
```

### Method 3: Docker

```bash
# Clone the repository
git clone <repository-url>
cd mantle2

# Start services
docker-compose up -d

# Install Drupal
docker-compose exec web vendor/bin/drush site:install standard --yes \
  --db-url="mysql://drupal:drupal@db/mantle2" \
  --site-name="Mantle2"
```

## Configuration

### Environment Configuration

1. **Copy environment template:**
   ```bash
   cp .env.example .env
   ```

2. **Update configuration values:**
   - Database credentials
   - Hash salt
   - External service URLs
   - Cache settings

### Database Configuration

For manual setup, add to `web/sites/default/settings.php`:

```php
$databases['default']['default'] = [
  'database' => 'mantle2',
  'username' => 'drupal',
  'password' => 'drupal',
  'prefix' => '',
  'host' => 'localhost',
  'port' => '3306',
  'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
  'driver' => 'mysql',
  'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
];
```

### Security Configuration

1. **Generate unique hash salt:**
   ```bash
   vendor/bin/drush eval "echo \Drupal\Component\Utility\Crypt::randomBytesBase64(55);"
   ```

2. **Set trusted host patterns in settings.php:**
   ```php
   $settings['trusted_host_patterns'] = [
     '^example\.com$',
     '^.+\.example\.com$',
   ];
   ```

## Development Workflow

### Code Quality Tools

The project includes automated code quality checks:

```bash
# Check code style
composer lint

# Fix code style issues
composer lint-fix

# Run static analysis
composer analyze

# Run unit tests
composer test

# Run all quality checks
make qa
```

### Development Commands

Using Makefile:
```bash
make help          # Show available commands
make install       # Install and setup project
make start         # Start development environment
make cr            # Clear Drupal cache
make test          # Run tests
make lint          # Check code style
```

Using Composer:
```bash
composer lint      # Code style check
composer lint-fix  # Fix code style
composer analyze   # Static analysis
composer test      # Unit tests
```

Using DDEV:
```bash
ddev start         # Start environment
ddev stop          # Stop environment
ddev ssh           # Access container
ddev drush [cmd]   # Run Drush commands
ddev composer [cmd] # Run Composer commands
```

## Custom Modules

### Mantle Core Module

Location: `web/modules/custom/mantle_core/`

Features:
- Core application logic
- Service definitions
- Configuration management
- Helper functions

**Key Files:**
- `mantle_core.info.yml` - Module definition
- `mantle_core.module` - Hook implementations
- `mantle_core.services.yml` - Service definitions
- `src/MantleCoreManager.php` - Core service class

**Usage:**
```php
// Get the core manager service
$manager = \Drupal::service('mantle_core.manager');
$app_name = $manager->getApplicationName();
```

## Custom Theme

### Mantle Theme

Location: `web/themes/custom/mantle_theme/`

Features:
- Extends Olivero base theme
- Custom styling
- JavaScript enhancements
- Responsive design

**Key Files:**
- `mantle_theme.info.yml` - Theme definition
- `mantle_theme.libraries.yml` - Asset libraries
- `css/style.css` - Custom styles
- `js/mantle.js` - Custom JavaScript

## Testing

### Unit Tests

Run unit tests for custom modules:

```bash
# All tests
composer test

# Specific module
vendor/bin/phpunit web/modules/custom/mantle_core/tests/

# With coverage
vendor/bin/phpunit --coverage-html coverage/
```

### Integration Tests

```bash
# Using DDEV
ddev drush test-run mantle_core

# Manual
vendor/bin/phpunit --group mantle_core
```

## Deployment

### Production Deployment

1. **Prepare for production:**
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

2. **Build assets:**
   ```bash
   # If using build tools
   npm run build
   ```

3. **Clear caches:**
   ```bash
   vendor/bin/drush cache:rebuild
   ```

4. **Update database:**
   ```bash
   vendor/bin/drush updatedb
   vendor/bin/drush config:import
   ```

### Docker Deployment

```bash
# Build production image
docker build -t mantle2:latest .

# Deploy with compose
docker-compose -f docker-compose.prod.yml up -d
```

### Environment-Specific Settings

**Local Development:**
- Caching disabled
- Debug mode enabled
- Verbose error reporting

**Staging:**
- Caching enabled
- Debug mode disabled
- Basic authentication

**Production:**
- All optimizations enabled
- Error logging only
- Security headers configured

## Monitoring and Logging

### Log Files

- **Drupal logs:** Admin > Reports > Recent log messages
- **Apache logs:** `/var/log/apache2/error.log`
- **PHP errors:** `/var/log/apache2/php_errors.log`

### Performance Monitoring

- **New Relic:** Configure in settings.php
- **Blackfire:** Install Blackfire extension
- **Drupal Performance:** Enable performance modules

## Troubleshooting

### Common Issues

1. **Memory errors:**
   ```php
   ini_set('memory_limit', '512M');
   ```

2. **File permissions:**
   ```bash
   chmod -R 755 web/sites/default/files
   chmod 644 web/sites/default/settings.php
   ```

3. **Cache issues:**
   ```bash
   vendor/bin/drush cache:rebuild
   ```

### Debug Tools

- **Devel module:** Enable for development
- **Webprofiler:** Performance debugging
- **Kint:** Variable dumping

## Security Considerations

### Best Practices

1. **Keep core and modules updated**
2. **Use strong passwords**
3. **Enable security modules**
4. **Regular security audits**
5. **Proper file permissions**

### Security Modules

- **Security Kit:** XSS protection
- **Password Policy:** Password requirements
- **Two-factor Authentication:** Enhanced login security

## Support and Resources

### Documentation

- [Drupal.org Documentation](https://www.drupal.org/docs)
- [DDEV Documentation](https://ddev.readthedocs.io/)
- [Composer Documentation](https://getcomposer.org/doc/)

### Community

- [Drupal Community](https://www.drupal.org/community)
- [Drupal Slack](https://drupal.slack.com)
- [Stack Overflow](https://stackoverflow.com/questions/tagged/drupal)

### Project Resources

- Repository issue tracker
- Project documentation
- Development team contacts