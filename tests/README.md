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

## Running a Single Tier / File

```sh
./vendor/bin/phpunit tests/src/Integration/Controller/GeneralControllerTest.php --no-coverage
```

`phpunit.xml.dist` points `SIMPLETEST_DB` at `sqlite://localhost/:memory:`, so each
worker gets a private database and parallel runs cannot contend. Nothing has to be
overridden per scope.

## Speed

The integration tier boots a full Drupal kernel in `setUp` for every test method,
so total runtime is dominated by fixed per-test cost — roughly 0.8s of container
compile plus 0.4s of field installs, before the test itself does anything. What
keeps that down:

- **In-memory SQLite.** No file I/O for schema installs that are thrown away
  immediately.
- **A process-wide FileCache backend** (`StaticFileCacheBackend`). Core only
  wires one when APCu is loaded; without it every boot reparses every module
  `.info.yml` and `*.services.yml`. Guarded by
  `SmokeTest::fileCacheBackendIsInstalled`.
- **One save per field in `createField()`** instead of three. An unchanged field
  now costs nothing on reinstall, which is also what production redeploys do.
- **CI sharding** (below) — the only lever that scales.

### Process isolation

`KernelTestBase::__construct()` calls `setRunTestInSeparateProcess(TRUE)` (Drupal
11.3, issue 3548485), so each test method runs in a fresh PHP process. Dropping
that is tempting and it does halve a **sequential** run, but it measured ~20%
slower under 8 paratest workers, reproducibly:

| config                              | wall     | user  | sys  |
| ----------------------------------- | -------- | ----- | ---- |
| isolated, whole suite `-p8`         | **5:01** | 1379s | 870s |
| shared, whole suite `-p8`           | 6:08     | 2375s | 453s |
| isolated, 103-test class, 1 process | 3:31     | 138s  | 66s  |
| shared, 103-test class, 1 process   | **1:36** | 71s   | 24s  |

A shared worker's heap grows across the tests it runs, and at high concurrency
the extra GC and memory traffic cost more than the forks save. So the default
follows core, and `MANTLE2_TEST_ISOLATION=0` opts into the shared process — worth
it when running one file locally, not for the parallel suite.
`SmokeTest::processIsolationFollowsTheEnvironment` asserts the flag matches the
environment in both directions.

The E2E tier always isolates: it talks to a live cloud worker and a real Redis,
and its fixtures accumulate across a run.

### What does not work

Caching the compiled service container across tests. A rehydrated container
definition loses Drupal 11's OOP hook implementations, so `field` stops
contributing field storage definitions through `hook_entity_field_storage_info()`
and every `createField()` call fails with "the field storage does not exist".
Re-collecting the hooks costs what the cache saved.

## CI Sharding

`.github/workflows/build.yml` splits the integration suite across a job matrix.
Each shard runs `tests/shard.php`, which reads `phpunit --list-tests`, bin-packs
the classes longest-first, and writes a `phpunit.shard.xml` containing only that
shard's files. The plan is a pure function of the inventory, so every shard job
derives the same assignment without coordinating.

```sh
bun run test:shard -- --suite=Integration --total=10 --plan         # show the split
bun run test:shard -- --suite=Integration --index=3 --total=10      # write phpunit.shard.xml
```

Within a shard, paratest runs in `--functional` mode so a single large class is
spread over the cores instead of pinning one worker. Each shard uploads its own
Clover report; `tests/coverage-summary.php` merges them back into one figure for
the job summary.
