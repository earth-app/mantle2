# mantle2

> Backend for The Earth App, powered by Drupal 11

This is the second version of the backend system for The Earth App, a comprehensive RESTful API built on top of PHP 8.4 and Drupal 11.2. The module provides a complete backend infrastructure for a social networking platform focused on environmental activities, events, and user engagement.

## Table of Contents

- [Overview](#overview)
- [Technical Stack](#technical-stack)
- [Architecture](#architecture)
- [Installation](#installation)
- [API Structure](#api-structure)
- [Core Components](#core-components)
- [Security & Performance](#security--performance)
- [Development](#development)
- [Testing](#testing)

## Overview

Mantle2 is a custom Drupal 11 module that implements a RESTful API backend for The Earth App. It leverages Drupal's entity system, field API, and routing infrastructure while adding custom controllers, services, and event subscribers to create a modern, scalable API platform.

### Key Features

- **RESTful API** with OpenAPI/Swagger documentation
- **User Management** with authentication, profiles, and social features
- **Activity Tracking** for environmental activities with custom fields
- **Event Management** with types, locations, and participation tracking
- **Prompt System** for daily challenges and user engagement
- **Article Content** with versioning and user attribution
- **Rate Limiting** with configurable per-endpoint and global limits
- **CORS Support** with origin whitelisting
- **Redis Caching** with automatic fallback to Drupal cache
- **Email Notifications** with HTML rendering and verification codes

## Technical Stack

### Core Technologies

- **PHP**: 8.4+
- **Drupal Core**: 11.2+
- **Symfony**: 7.3+ (Event Dispatcher, Rate Limiter, Cache)
- **Redis**: Optional caching layer via `drupal/redis` module
- **PostgreSQL/MySQL**: Database backend (Drupal standard)

### Development Tools

- **Composer**: PHP dependency management
- **Bun**: JavaScript runtime for development tooling
- **PHPUnit**: 11.5+ for unit testing
- **Drush**: 13.6+ for Drupal CLI operations
- **PHPStan**: Static analysis
- **PHP CodeSniffer**: Code quality enforcement
- **Prettier**: Code formatting for PHP, XML, YAML, JSON

### Key Dependencies

```json
{
	"drupal/core": "^11.2",
	"drupal/json_field": "^1.4", // JSON field storage
	"drupal/key": "1.20", // API key management
	"drupal/smtp": "^1.4", // Email delivery
	"drupal/redis": "^1.10", // Redis integration
	"symfony/rate-limiter": "^7.3", // Rate limiting
	"symfony/event-dispatcher": "^7.3" // Event system
}
```

## Architecture

### Directory Structure

```
mantle2/
├── src/
│   ├── Controller/          # API endpoint controllers
│   │   └── Schema/          # OpenAPI schema generators
│   ├── Custom/              # Domain models and enums
│   ├── EventSubscriber/     # Symfony event subscribers
│   └── Service/             # Business logic helpers
├── tests/
│   └── src/
│       ├── Unit/            # PHPUnit tests
│       └── Mocks.php        # Test fixtures
├── mantle2.info.yml         # Module metadata
├── mantle2.module           # Hook implementations
├── mantle2.install          # Installation & schema
├── mantle2.routing.yml      # API route definitions (100+ endpoints)
├── mantle2.services.yml     # Service container definitions
├── composer.json            # PHP dependencies
├── package.json             # Dev tooling
└── phpunit.xml.dist         # Test configuration
```

### Design Patterns

#### 1. Controller Layer

All API endpoints are implemented as controller methods extending Drupal's `ControllerBase`:

```php
class UsersController extends ControllerBase
{
	public function users(Request $request): JsonResponse
	{
		// Handle paginated user listing
	}

	public function login(Request $request): JsonResponse
	{
		// Authenticate and return session token
	}
}
```

#### 2. Service Layer

Business logic is encapsulated in helper services registered in `mantle2.services.yml`:

- **GeneralHelper**: Common utilities (pagination, validation)
- **UsersHelper**: User operations and authentication
- **ActivityHelper**: Activity-related business logic
- **RedisHelper**: Cache abstraction with fallback

#### 3. Domain Models

Custom PHP classes in `src/Custom/` represent business entities:

- Implement `JsonSerializable` for API responses
- Enforce validation in constructors
- Provide type-safe interfaces

```php
class Activity implements JsonSerializable
{
	protected string $id;
	protected string $name;
	protected array $types = [];
	protected ?string $description = null;

	public const int MAX_TYPES = 5;

	public function __construct(
		string $id,
		string $name,
		array $types = [],
		?string $description = null,
		array $aliases = [],
		array $fields = [],
	) {
		if (count($types) > self::MAX_TYPES) {
			throw new InvalidArgumentException('Too many activity types');
		}
		// ... validation and initialization
	}
}
```

#### 4. Event Subscribers

Symfony's event dispatcher handles cross-cutting concerns:

- **RateLimitSubscriber**: Pre-request rate limit enforcement
- **CorsSubscriber**: CORS header injection
- **ApiExceptionSubscriber**: Global error handling

## Installation

### Prerequisites

- PHP 8.4 or higher
- Composer 2.x
- Drupal 11.2+ installed and configured
- Redis server (optional, recommended for production)
- SMTP server credentials for email functionality

### Steps

1. **Clone the repository** into your Drupal modules directory:

    ```bash
    cd /path/to/drupal/modules/custom
    git clone <repository-url> mantle2
    cd mantle2
    ```

2. **Install PHP dependencies**:

    ```bash
    composer install
    ```

3. **Enable required Drupal modules**:

    ```bash
    drush en node user comment json_field key field options datetime smtp redis -y
    ```

4. **Enable mantle2**:

    ```bash
    drush en mantle2 -y
    ```

    This runs the installation hooks in `mantle2.install` which:
    - Creates custom content types (Activity, Event, Article, Prompt)
    - Creates custom comment types (Activity Comments, Article Comments)
    - Defines 50+ custom fields with JSON storage
    - Sets up user profile fields
    - Configures field display settings

5. **Configure Redis** (optional):
   Edit `settings.php`:

    ```php
    $settings['redis.connection']['interface'] = 'PhpRedis';
    $settings['redis.connection']['host'] = '127.0.0.1';
    $settings['redis.connection']['port'] = 6379;
    $settings['cache']['default'] = 'cache.backend.redis';
    ```

6. **Configure SMTP** (required for email features):
    - Navigate to `/admin/config/system/smtp`
    - Enter SMTP server credentials
    - Test email delivery

7. **Clear cache**:
    ```bash
    drush cr
    ```

### Verification

Access the API documentation:

- OpenAPI Schema: `https://your-domain.com/openapi`
- Swagger UI: `https://your-domain.com/swagger-ui`

Test a simple endpoint:

```bash
curl https://your-domain.com/v2/hello
```

## Core Components

### Controllers

#### UsersController

**Responsibilities:**

- User CRUD operations
- Authentication (login/logout)
- Profile management (photos, privacy, account types)
- Social graph (friends, circle)
- Notifications management
- Email verification
- Activity associations

**Database Queries:**

- Uses both Entity API (`$storage->getQuery()`) and direct SQL (`Drupal::database()`)
- Random sorting implemented via `orderRandom()` for discovery features
- Supports search across multiple fields (username, first name, last name)

#### ActivityController

**Responsibilities:**

- Activity catalog management
- Activity comments
- Type filtering and categorization
- Activity-user associations

**Key Features:**

- Supports up to 5 activity types per activity
- JSON field storage for flexible metadata
- Full-text search across name, description, aliases
- Comment system with threading

#### EventsController

**Responsibilities:**

- Event lifecycle management
- Participation tracking
- Date-based filtering
- Location and event type handling

**Features:**

- Date range queries for event discovery
- RSVP/participation management
- Event type enumeration
- Geographic location support

#### PromptsController

**Responsibilities:**

- Daily prompt delivery
- User response collection
- Prompt scheduling and rotation

**Logic:**

- Calculates daily prompt based on date
- Tracks user responses
- Prevents duplicate responses per user per prompt

#### ArticlesController

**Responsibilities:**

- Article content management
- Version control
- Content moderation
- Author attribution

**Features:**

- Full versioning of content changes
- Comment integration
- Rich text content support via JSON fields

### Services

#### GeneralHelper

**Utilities:**

- `paginatedParameters(Request)`: Validates and extracts pagination params
- `findOrdinal(array, enum)`: Maps enum values to database integers
- `validateJson(string)`: JSON validation
- Various format converters and validators

#### UsersHelper

**User Operations:**

- `getOwnerOfRequest(Request)`: Extract authenticated user from request
- `getUserFromUsernameOrId(string)`: Flexible user lookup
- `validatePassword(string)`: Password strength validation
- `hashPassword(string)`: Secure password hashing
- `generateSessionToken()`: Create authentication tokens
- Friend/circle relationship management

#### RedisHelper

**Caching Abstraction:**

```php
RedisHelper::set(string $key, array $data, int $ttl = 900): bool
RedisHelper::get(string $key): ?array
RedisHelper::delete(string $key): bool
RedisHelper::exists(string $key): bool
```

**Features:**

- Automatic fallback to Drupal cache backend if Redis unavailable
- JSON serialization of complex data structures
- TTL support for automatic expiration
- Connection pooling via Drupal Redis module

**Usage Example:**

```php
// Store email verification code
RedisHelper::set(
	"email_verify:{$userId}",
	[
		'code' => $code,
		'email' => $email,
		'created' => time(),
	],
	900,
); // 15 minutes TTL

// Retrieve and validate
$data = RedisHelper::get("email_verify:{$userId}");
if ($data && $data['code'] === $userInputCode) {
	// Verify successful
	RedisHelper::delete("email_verify:{$userId}");
}
```

#### HTMLFactory

**Email Rendering:**

- Converts markdown to HTML for email templates
- Applies consistent styling
- Handles inline CSS for email clients
- Supports template variables

### Domain Models

#### Activity

```php
class Activity implements JsonSerializable
{
	protected string $id;
	protected string $name;
	protected array $types; // ActivityType[]
	protected ?string $description;
	protected array $aliases; // Alternative names
	protected array $fields; // Custom metadata

	public const int MAX_TYPES = 5;
}
```

#### Event

```php
class Event implements JsonSerializable
{
	protected int $id;
	protected string $name;
	protected string $description;
	protected EventType $type;
	protected DateTimeImmutable $start;
	protected DateTimeImmutable $end;
	protected ?string $location;
	protected int $creatorId;
	protected array $participantIds;
}
```

#### Notification

```php
class Notification implements JsonSerializable
{
	protected int $id;
	protected string $title;
	protected string $message;
	protected NotificationType $type;
	protected bool $read;
	protected DateTimeImmutable $created;
	protected ?array $metadata;
}
```

#### Enums (PHP 8.1+)

**AccountType:**

- `STANDARD`: Regular user
- `PREMIUM`: Premium features
- `ADMIN`: Administrative access

**Visibility:**

- `PUBLIC`: Visible to all
- `FRIENDS`: Friends only
- `PRIVATE`: Only user

**Privacy:**

- Settings for profile fields, activities, friends list

**ActivityType, EventType, NotificationType:**

- Enumerated types for categorization

### Event Subscribers

#### RateLimitSubscriber

**Configuration:**

```php
// Global limits
Authenticated: 1000 requests / 15 minutes
Anonymous: 100 requests / 15 minutes

// Per-endpoint limits (examples)
POST /v2/users/login: 5 requests / 5 minutes
POST /v2/users/create: 3 requests / hour
GET /v2/users: 100 requests / minute
```

**Implementation:**

- Uses Drupal's expirable key-value store
- Separate counters for global and per-endpoint limits
- IP-based tracking (Cloudflare-aware)
- Returns `429 Too Many Requests` with retry headers

**Headers Added:**

```
X-RateLimit-Limit: 1000
X-RateLimit-Remaining: 847
X-RateLimit-Reset: 1698765432
X-Global-RateLimit-Limit: 1000
X-Global-RateLimit-Remaining: 999
```

#### CorsSubscriber

**Allowed Origins:**

```php
[
	'https://api.earth-app.com',
	'https://earth-app.com',
	'https://app.earth-app.com',
	'https://cloud.earth-app.com',
	'http://localhost:3000', // Development only
	'http://127.0.0.1:3000', // Development only
];
```

**Headers Set:**

```
Access-Control-Allow-Origin: <matched-origin>
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With, X-Admin-Key
Access-Control-Allow-Credentials: true
Access-Control-Max-Age: 3600
Vary: Origin
```

#### ApiExceptionSubscriber

**Error Handling:**

- Catches unhandled exceptions in API routes
- Converts to consistent JSON error responses
- Logs errors with context
- Prevents sensitive information leakage in production

## Security & Performance

### Security Features

#### 1. Authentication & Authorization

- Session-based authentication with secure token generation
- Password hashing using Drupal's password API (bcrypt)
- Password strength validation
- Email verification for account creation
- Two-factor authentication support via verification codes
- Admin key validation for privileged operations

#### 2. Input Validation

- Request parameter sanitization
- JSON schema validation
- SQL injection prevention via Entity API and parameterized queries
- XSS protection through Drupal's filtering system
- File upload validation (type, size, permissions)

#### 3. Rate Limiting

- IP-based rate limiting
- Separate limits for authenticated vs. anonymous users
- Per-endpoint rate limiting for sensitive operations
- Configurable time windows and thresholds
- Cloudflare IP detection support

#### 4. Privacy Controls

- User-level visibility settings (PUBLIC, FRIENDS, PRIVATE)
- Field-level privacy for profile attributes
- Friend/circle-based content filtering
- Profile photo access control

#### 5. CORS Protection

- Origin whitelist enforcement
- Credentials support for trusted domains
- Preflight request handling

### Performance Optimizations

#### 1. Caching Strategy

```php
// Redis for session data, verification codes
RedisHelper::set("session:{$token}", $sessionData, 3600);

// Entity caching via Drupal cache tags
$users = $storage->loadMultiple($uids);

// Query result caching
$cache = Drupal::cache('mantle2');
$cache->set($key, $data, $expire, $tags);
```

#### 2. Database Optimization

- Indexed fields for common queries (username, email, ID)
- Pagination to limit result sets
- Direct SQL for complex queries (random sorting)
- Query object cloning for count queries to avoid duplication

#### 3. Lazy Loading

- User entities loaded on-demand
- Related entities fetched only when needed
- JSON fields decoded on access

#### 4. Efficient Queries

```php
// Good: Load multiple entities at once
$users = $storage->loadMultiple($uids);

// Good: Paginated with limit
$query->range($offset, $limit);

// Good: Specific field loading
$query->fields('u', ['uid', 'name', 'mail']);
```

### Monitoring & Logging

**Drupal Logger Integration:**

```php
Drupal::logger('mantle2')->error('Error message', ['context' => $data]);
Drupal::logger('mantle2')->warning('Warning message');
Drupal::logger('mantle2')->info('Info message');
```

**Logged Events:**

- Failed login attempts
- Rate limit violations
- Email delivery failures
- Redis connection issues
- API exceptions
- User registrations
- Password changes

## Development

### Local Setup

1. **Install dependencies:**

    ```bash
    composer install
    npm install  # or: bun install
    ```

2. **Configure local environment:**

    ```php
    // settings.local.php
    $config['system.logging']['error_level'] = 'verbose';
    $settings['redis.connection']['host'] = 'localhost';
    ```

3. **Enable development modules:**

    ```bash
    drush en devel devel_generate dblog -y
    ```

4. **Generate test data:**
    ```bash
    drush devel-generate-users 50
    drush devel-generate-content 100 --types=activity,event,article
    ```

### Code Quality

**Formatting:**

```bash
# PHP, YAML, XML, JSON
npm run prettier          # Format all files
npm run prettier:check    # Check formatting

# PHP-specific
vendor/bin/phpcbf        # Auto-fix coding standards
vendor/bin/phpcs         # Check coding standards
```

**Static Analysis:**

```bash
vendor/bin/phpstan analyse src/
```

**Pre-commit Hooks:**
Configured via Husky and lint-staged in `package.json`:

```json
{
	"lint-staged": {
		"*.{php,xml,json,yml,md}": "prettier --write"
	}
}
```

### API Documentation

**Generate OpenAPI Schema:**
Visit `/openapi` to see auto-generated schema based on route definitions in `mantle2.routing.yml`.

**Interactive Swagger UI:**
Visit `/swagger-ui` for interactive API testing and documentation.

**Schema Annotations:**
Routes include OpenAPI metadata:

```yaml
mantle2.users:
    path: '/v2/users'
    options:
        tags: Users
        description: Retrieves a list of Earth App users
        schema/200: users() # Links to schema definition
        schema/400: Invalid Pagination Parameters
        query: # Query parameter schema
            limit:
                type: integer
                minimum: 1
                maximum: 100
```

### Debugging

**Enable Verbose Errors:**

```php
// settings.local.php
$config['system.logging']['error_level'] = 'verbose';
error_reporting(E_ALL);
ini_set('display_errors', true);
```

**Database Queries:**

```bash
drush watchdog:show --type=mantle2
drush ws --tail  # Live log tail
```

**Clear Caches:**

```bash
drush cr                  # Full cache rebuild
drush cc views            # Clear specific bin
drush redis-cli flushall  # Clear Redis
```

## Testing

### PHPUnit Configuration

**Location:** `phpunit.xml.dist`

**Run Tests:**

```bash
# All tests
vendor/bin/phpunit

# Specific test file
vendor/bin/phpunit tests/src/Unit/UserHelperTest.php

# With coverage
vendor/bin/phpunit --coverage-html coverage/
```

### Unit Tests

**Test Structure:**

```php
namespace Drupal\Tests\mantle2\Unit;

use PHPUnit\Framework\TestCase;
use Drupal\mantle2\Service\UsersHelper;

class UsersHelperTest extends TestCase
{
	public function testPasswordValidation()
	{
		$this->assertTrue(UsersHelper::validatePassword('SecureP@ss123'));
		$this->assertFalse(UsersHelper::validatePassword('weak'));
	}
}
```

### Mocks

**Location:** `tests/src/Mocks.php`

Provides test fixtures for:

- User entities
- Activity nodes
- Event nodes
- Request objects
- Service mocks

### Integration Testing

**Use Drush:**

```bash
# Test API endpoints
drush php-eval "print_r(\Drupal::service('http_kernel')->handle(Request::create('/v2/hello')));"

# Test services
drush php-eval "print_r(\Drupal::service('mantle2.helper.user')->validatePassword('test'));"
```

### API Testing

**Using cURL:**

```bash
# Health check
curl http://localhost/v2/hello

# Login
curl -X POST http://localhost/v2/users/login \
  -H "Content-Type: application/json" \
  -d '{"username":"testuser","password":"testpass"}'

# Get users (authenticated)
curl http://localhost/v2/users \
  -H "Authorization: Bearer <token>"
```

**Using Postman/Insomnia:**
Import the OpenAPI schema from `/openapi` for automatic request generation.

## License

See [LICENSE](LICENSE) file for details.

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Run tests and formatting (`composer test && npm run prettier`)
5. Push to the branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

## Support

For issues, questions, or contributions, please open an issue on the GitHub repository.

---

**Built with ❤️ for The Earth App**
