<?php

/**
 * LIBRARY_TASK_TEST_SAFETY — proves automated tests can never target the live
 * MySQL `laravel` database.
 *
 * Background: on 2026-09-08 `docker exec scisp_backend php artisan test` ran
 * RefreshDatabase against the live database and destroyed all Library data.
 * Root cause: PHPUnit only applies <env> when the variable is absent from the real
 * environment, and docker-compose exports DB_CONNECTION=mysql / DB_DATABASE=laravel.
 *
 * These tests are the always-on assertion layer. They deliberately use NO
 * RefreshDatabase, so they touch no database at all.
 */

use Illuminate\Support\Facades\DB;

test('tests never resolve to a protected database', function () {
    $connection = config('database.default');
    $database = (string) config("database.connections.{$connection}.database");

    expect($database)->not->toBe('laravel');
    expect(DB::connection()->getDatabaseName())->not->toBe('laravel');
});

test('tests run on an isolated in-memory sqlite connection', function () {
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');
});

test('phpunit.xml env overrides win over the container environment', function () {
    // Proves force="true" defeated docker-compose's DB_CONNECTION=mysql.
    expect(env('DB_CONNECTION'))->toBe('sqlite');
    expect(env('DB_DATABASE'))->toBe(':memory:');
    expect(env('APP_ENV'))->toBe('testing');
});

/*
|--------------------------------------------------------------------------
| Guard self-test
|--------------------------------------------------------------------------
| Under Q-T1 full lockout, phpunit.xml force="true" also overrides
| `docker exec -e`, so the original §7.3 negative test can no longer steer the
| connection to MySQL from outside. Weakening force="true" to make that command
| work is explicitly forbidden.
|
| Instead this exercises the REAL guard by mutating the resolved config at
| runtime and invoking it directly. It is safe: no RefreshDatabase is used and
| the guard aborts before any connection is opened, so nothing can be written.
|
| Skipped unless explicitly requested:
|   docker exec -e GUARD_SELFTEST=1 scisp_backend \
|     php artisan test --filter="guard aborts"
|
| Expected: the ABORTED banner on STDERR and a non-zero exit code.
*/
test('guard aborts when the resolved database is protected', function () {
    if (getenv('GUARD_SELFTEST') !== '1') {
        $this->markTestSkipped('Set GUARD_SELFTEST=1 to run the guard self-test (it exits the process by design).');
    }

    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'laravel',
    ]);

    // Calls the real guard in Tests\TestCase. This must terminate the process
    // with exit(1); execution never returns past this line.
    $this->guardAgainstProtectedDatabase();

    $this->fail('Guard did not abort on a protected database — SAFETY FAILURE.');
});
