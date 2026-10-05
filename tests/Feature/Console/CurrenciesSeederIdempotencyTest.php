<?php

namespace Tests\Feature\Console;

use App\Models\Currency;
use App\Models\User;
use Database\Seeders\CurrenciesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * V1 deployment readiness — `php artisan platform:install` is documented as
 * safe to repeat and runs `db:seed` (and so CurrenciesSeeder) every time. The
 * seeder used to create() a full new set of currencies on every run (12 more
 * rows per install). This pins it as idempotent.
 */
class CurrenciesSeederIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_running_the_seeder_repeatedly_does_not_duplicate_currencies(): void
    {
        // The seeder hard-codes user_id 1 (the first Platform Owner, which
        // platform:install guarantees exists before db:seed).
        User::forceCreate([
            'id' => 1, 'first_name' => 'Platform', 'last_name' => 'Owner',
            'email' => 'owner@example.test', 'status' => true, 'is_admin' => true,
            'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->seed(CurrenciesSeeder::class);
        $first = Currency::query()->count();
        $uids = Currency::query()->orderBy('code')->pluck('uid', 'code')->all();

        $this->seed(CurrenciesSeeder::class);
        $this->seed(CurrenciesSeeder::class);

        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, Currency::query()->count());
        $this->assertSame($first, Currency::query()->distinct()->count('code'), 'One row per currency code.');
        $this->assertSame($uids, Currency::query()->orderBy('code')->pluck('uid', 'code')->all(), 'Existing rows are left untouched.');
    }
}
