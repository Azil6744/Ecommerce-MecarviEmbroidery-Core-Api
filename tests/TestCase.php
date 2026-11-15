<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Tests rebuild or roll back tables. phpunit.xml points them at "mecarvi_ecommerce_testing", but a
     * cached config (`php artisan config:cache`) would silently send them to the real database.
     * This runs after the app boots and before any database trait: stop unless it is a test database.
     */
    protected function setUpTraits()
    {
        $default = (string) config('database.default');
        $driver = (string) config("database.connections.{$default}.driver");
        $database = (string) config("database.connections.{$default}.database");
        $isTestDatabase = ($driver === 'sqlite' && $database === ':memory:') || str_ends_with($database, '_testing');

        if (! $isTestDatabase) {
            fwrite(STDERR, "\nRefusing to run tests against the {$driver} database \"{$database}\": it is not a test database.\n"
                . "Run `php artisan config:clear` so phpunit.xml's test database applies.\n");
            exit(1);
        }

        return parent::setUpTraits();
    }
}
