# mantle2

Backend module for The Earth App, built on Drupal 11 and PHP 8.4.

## Working Rules

- Keep changes small and local to the owning controller, helper, or YAML config file.
- Before editing routes, services, caching rules, or Drush definitions, check the matching unit test file and keep them aligned.
- Prefer existing helpers and services over adding new controller-local utility code.
- Consolidate related logic into the owning helper/controller rather than spreading it across new files — e.g. nearly all user logic lives in `UsersHelper` (~3000 lines); add new user helpers there as a clearly-commented section. Organize large files with section comments instead of splitting them up.
- Do not edit generated output in `coverage/` or dependencies in `vendor/`.
- Avoid secrets or credential changes unless the task explicitly requires them.

## Project Map

- `src/Controller/` - API controllers for users, activities, events, prompts, articles, general endpoints, and schema docs.
- `src/Service/` - business logic helpers such as `GeneralHelper`, `UsersHelper`, `ActivityHelper`, `EventsHelper`, `CampaignHelper`, `CloudHelper`, `PointsHelper`, `PromptsHelper`, `ArticlesHelper`, `RedisHelper`, and `HTMLFactory`.
- `src/Custom/` - domain enums and value objects used in API responses and persistence.
- `src/EventSubscriber/` - request/response cross-cutting concerns including rate limiting, caching, CORS, XML negotiation, and exception handling.
- `src/Plugin/OpenIDConnectClient/` - provider plugins for external login integrations.
- `src/Commands/` - Drush command entrypoints.
- `data/` - runtime data files such as `email_campaigns.yml` and `service-account.json`.
- `tests/src/Unit/` - PHPUnit unit tests, mostly YAML/config validation and helper behavior checks.
- `tests/src/Mocks.php` - Drupal container and service mocks for helper tests.
- `.github/workflows/` - CI for formatting, deploy, and external E2E dispatch.

## Important Files

- `mantle2.info.yml` - Drupal module metadata and dependencies.
- `mantle2.services.yml` - service container registrations for helpers and subscribers.
- `mantle2.routing.yml` - route definitions and OpenAPI metadata for the API surface.
- `mantle2.caching.yml` - route-level read cache and invalidation rules.
- `drush.services.yml` - Drush command service registration.
- `composer.json` - PHP dependencies and PSR-4 autoloading.
- `package.json` - Prettier and Husky/lint-staged tooling.
- `phpunit.xml.dist` - PHPUnit configuration and coverage outputs.

## Backend Usage

- Public API routes are under `/v2`; schema docs are exposed at `/openapi` and `/swagger-ui`.
- Module enablement typically goes through Drush: `drush en mantle2 -y`, then `drush cr` after config changes.
- Deployment and fresh server setup usually involve `composer install`, `drush en mantle2 -y`, `drush updb -y`, and `drush cr`.
- Drush commands are implemented in `src/Commands/Mantle2Commands.php` and are registered through `drush.services.yml`.
- `data/email_campaigns.yml` drives campaign content and placeholder expansion; keep placeholder families consistent with `CampaignHelper` tests.
- `data/service-account.json` is used by cloud-related code paths; treat it as sensitive runtime data.

## Drupal Configuration

- `mantle2.services.yml` is the source of truth for helper and subscriber wiring.
- `mantle2.routing.yml` is heavily validated by tests; route paths, requirements, controller methods, and HTTP verbs should stay consistent.
- `mantle2.caching.yml` must stay in sync with route names and path parameters because tests compare cache rules against routing patterns.
- `mantle2.info.yml` lists core Drupal module dependencies such as `node`, `user`, `comment`, `json_field`, `key`, `datetime`, `smtp`, and `redis`.
- `drush.services.yml` should keep command service names in the `mantle2.*` namespace and classes ending in `Commands`.

## Unit Test Style

- PHPUnit tests live in `tests/src/Unit/` and use `PHPUnit 11` attributes such as `#[Test]`, `#[TestDox]`, `#[Group]`, and `#[DataProvider]`.
- YAML/config validation tests are common: they parse the target file with `Symfony\Component\Yaml\Yaml`, then assert required keys, naming conventions, and class existence.
- Typical groups are `mantle2/services`, `mantle2/routing`, `mantle2/caching`, `mantle2/drush`, `mantle2/html`, `mantle2/cloud`, and `mantle2/util`.
- `tests/src/Mocks.php` provides container/service stubs for helper tests that need Drupal runtime behavior.
- Prefer targeted tests for the file you changed instead of running the full suite when the change is localized.

## Validation

- For formatting changes, run `bun run prettier:check` or `bun run prettier`.
- For config edits, run the matching unit test file or group first, then expand only if needed.
- For YAML routes/services/caching edits, make sure the corresponding validation test still passes.
- For helper or controller logic, use the narrowest PHPUnit test that covers the touched path.
