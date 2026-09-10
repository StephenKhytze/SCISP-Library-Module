<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }

    /**
     * Hard fail-safe: refuse to run tests against a protected database.
     *
     * Placement is critical. Illuminate\Foundation\Testing\TestCase::setUp() runs
     * refreshApplication() and THEN setUpTraits(), and setUpTraits() is where
     * RefreshDatabase executes migrate:fresh. Guarding here is the only window in
     * which aborting still prevents data loss — a check placed after parent::setUp()
     * would run only after the database had already been wiped.
     *
     * See LIBRARY_TASK_TEST_SAFETY.md.
     */
    protected function refreshApplication()
    {
        parent::refreshApplication();

        $this->guardAgainstProtectedDatabase();
    }

    protected function guardAgainstProtectedDatabase(): void
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $url = (string) config("database.connections.{$connection}.url");

        // In-memory SQLite is always safe.
        if ($connection === 'sqlite' && $database === ':memory:') {
            return;
        }

        $protected = array_filter(array_map(
            'trim',
            explode(',', (string) env('TEST_PROTECTED_DATABASES', 'laravel'))
        ));

        $hit = in_array($database, $protected, true);

        foreach ($protected as $name) {
            if ($url !== '' && str_contains($url, '/'.$name)) {
                $hit = true;
            }
        }

        if ($hit) {
            fwrite(STDERR, PHP_EOL.str_repeat('=', 78).PHP_EOL
                .'ABORTED: tests resolved to a PROTECTED database.'.PHP_EOL
                .PHP_EOL
                ."  connection : {$connection}".PHP_EOL
                ."  database   : {$database}".PHP_EOL
                .PHP_EOL
                .'Running here would DESTROY live data (RefreshDatabase => migrate:fresh).'.PHP_EOL
                .'Tests must use sqlite :memory: or a dedicated testing database.'.PHP_EOL
                .'See LIBRARY_TASK_TEST_SAFETY.md.'.PHP_EOL
                .str_repeat('=', 78).PHP_EOL);

            exit(1);
        }
    }
}
