<?php

namespace Tests\Feature\Automations\Concerns;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * Isolation for tests that cannot run inside RefreshDatabase's per-test
 * transaction — because the code under test issues DDL (MySQL implicitly
 * commits) or because the proof needs a SECOND real database session that
 * must see committed fixture rows. DatabaseMigrations is not usable here
 * either: this repository's migration down() chain is not rollback-clean.
 *
 * A fresh schema is migrated before the test and again after it, and
 * RefreshDatabase is told it may reuse that fresh schema for the tests
 * that follow in the same process.
 */
trait UsesFreshSchema
{
    protected function setUpFreshSchema(): void
    {
        $this->freshSchema();

        $this->beforeApplicationDestroyed(function (): void {
            $this->freshSchema();
            RefreshDatabaseState::$migrated = true;
        });
    }

    private function freshSchema(): void
    {
        $this->artisan('migrate:fresh');
        $this->app[Kernel::class]->setArtisan(null);
    }
}
