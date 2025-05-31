<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

/**
 * Test case trait that sets up the SQLite in-memory database
 */
trait SetsUpDatabase
{
    use RefreshDatabase;

    /**
     * Set up the test database
     */
    protected function setUpDatabase(): void
    {
        // Run migrations for the test database
        Artisan::call('migrate');
    }

    /**
     * Run before each test
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDatabase();
    }
}
