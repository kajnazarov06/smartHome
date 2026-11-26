<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // RefreshDatabase wipes whatever database is configured: never let that be a real one.
        $connection = config('database.default');
        if (! app()->environment('testing') || $connection !== 'sqlite' || config("database.connections.{$connection}.database") !== ':memory:') {
            throw new RuntimeException(
                'Tests must run with APP_ENV=testing on in-memory SQLite (see phpunit.xml). '
                .'Got APP_ENV='.app()->environment().", DB_CONNECTION={$connection}.",
            );
        }
    }
}
