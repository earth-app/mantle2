# mantle2 Test Suite

Three tiers, three PHPUnit test suites, three Codecov flags. Run one tier with
`bun run test:unit` / `test:integration` / `test:e2e`, or all via `bun run test`.

| Tier        | Directory                | Base class                                                    | Codecov flag      | Boots a kernel? | External deps                  |
| ----------- | ------------------------ | ------------------------------------------------------------- | ----------------- | --------------- | ------------------------------ |
| Unit        | `tests/src/Unit/`        | `PHPUnit\Framework\TestCase` (or `Drupal\Tests\UnitTestCase`) | `unit`            | no              | none                           |
| Integration | `tests/src/Integration/` | `IntegrationTestBase`                                         | `e2e-mocked`      | yes (SQLite)    | cloud/OAuth/FCM/mail mocked    |
| E2E         | `tests/src/E2E/`         | `E2ETestBase`                                                 | `e2e-integration` | yes (SQLite)    | live cloud worker + real Redis |

## Bootstrap

`tests/bootstrap.php` is the single bootstrap for all tiers. It works in this
module-only repo (no full Drupal install): it registers core's test-suite
namespaces + every contrib module's `Drupal\<name>\` namespace, drops a
`vendor/drupal/autoload.php` shim, and symlinks `mantle2` + contrib packages
into `vendor/drupal/modules/` so `ExtensionDiscovery` finds them. Idempotent, so
it self-heals after `composer install`.

## Layout

- `tests/src/Unit/Custom/` — one file per `src/Custom` value object / enum.
- `tests/src/Unit/` — YAML/config/schema validation tests (routing, services,
  caching, drush, email campaigns).
- `tests/src/Integration/Controller/<Name>ControllerTest.php` — mirrors `src/Controller`.
- `tests/src/Integration/Service/<Name>HelperTest.php` — mirrors `src/Service`.
- `tests/src/Integration/EventSubscriber/<Name>Test.php` — mirrors `src/EventSubscriber`.
- `tests/src/E2E/<Surface>Test.php` — grouped by cloud surface.

## Naming and Structure Rules

- One file per controller; one test method per route OR route-group. A function
  that backs multiple routes (common in `UsersController`) gets one method.
- One file per service helper; one method per function or simple-CRUD group
  (batch trivial `assertEquals` CRUD together).
- Copy the helper's `#region` names into the test file as section markers.
- Every test carries `#[Test]`, `#[TestDox('...')]`, and `#[Group('mantle2/<area>')]`.
- Use `#[DataProvider]` wherever it removes duplication.
- Assert everything: status code, body shape, side effects, persisted rows.
- Little to no comments in test files. When one is unavoidable it is a single
  lowercase line, no trailing period.

## The `cloud` Boundary

Any flow that calls `CloudHelper::` (sendRequest / sendWebsocketMessage) is an
E2E test, not an integration test. Integration tests cover the local paths only;
cloud-backed paths are exercised in `tests/src/E2E/` against a live worker.

## Mocking (Integration Tier)

- Mail: `IntegrationTestBase` sets the `test_mail_collector` backend; read
  captured mail via `\Drupal::state()->get('system.test_mail_collector')`.
- OAuth: mock `plugin.manager.openid_connect_client` in the container.
- FCM: `FCMHelper::send` is inert without credentials (do not provision any).
- Redis: `RedisHelper` uses the `cache.mantle2` fallback (glob delete/list are
  unsupported there — those are covered in E2E against real Redis).

## Running a Single Tier / File (avoid DB contention when parallel)

```
SIMPLETEST_DB=sqlite://localhost//tmp/mantle2-<scope>.sqlite \
  ./vendor/bin/phpunit tests/src/Integration/Controller/GeneralControllerTest.php --no-coverage
```
