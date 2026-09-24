<?php

namespace Tests\Feature\Console;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `platform:create-owner` is the explicit, authorized replacement for the
 * hardcoded administrator `UserSeeder` used to insert. These tests cover
 * fresh installation (no administrator exists yet), repeat execution (one
 * already exists), and the validation that keeps every owner's credential
 * genuinely operator-supplied.
 */
class CreatePlatformOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_installation_creates_exactly_one_administrator(): void
    {
        $this->artisan('platform:create-owner', [
            '--email' => 'owner@example.test',
            '--password' => 'a-genuinely-long-password',
        ])->assertSuccessful();

        $this->assertSame(1, User::query()->where('is_admin', true)->count());

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue($owner->is_admin);
        $this->assertFalse((bool) $owner->is_customer);
        $this->assertTrue(Hash::check('a-genuinely-long-password', $owner->password));
    }

    public function test_it_attaches_the_administrator_role_when_one_exists(): void
    {
        $role = Role::create(['name' => 'administrator', 'status' => true]);

        $this->artisan('platform:create-owner', [
            '--email' => 'owner@example.test',
            '--password' => 'a-genuinely-long-password',
        ])->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue($owner->roles()->whereKey($role->id)->exists());
    }

    public function test_repeat_execution_without_force_refuses_and_creates_no_duplicate(): void
    {
        $this->artisan('platform:create-owner', [
            '--email' => 'owner@example.test',
            '--password' => 'a-genuinely-long-password',
        ])->assertSuccessful();

        $this->artisan('platform:create-owner', [
            '--email' => 'second-owner@example.test',
            '--password' => 'another-long-password',
        ])->assertFailed();

        $this->assertSame(1, User::query()->where('is_admin', true)->count());
        $this->assertDatabaseMissing('users', ['email' => 'second-owner@example.test']);
    }

    public function test_force_allows_creating_an_additional_owner(): void
    {
        $this->artisan('platform:create-owner', [
            '--email' => 'owner@example.test',
            '--password' => 'a-genuinely-long-password',
        ])->assertSuccessful();

        $this->artisan('platform:create-owner', [
            '--email' => 'second-owner@example.test',
            '--password' => 'another-long-password',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(2, User::query()->where('is_admin', true)->count());
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->artisan('platform:create-owner', [
            '--email' => 'owner@example.test',
            '--password' => 'short',
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
    }

    public function test_an_invalid_email_is_refused(): void
    {
        $this->artisan('platform:create-owner', [
            '--email' => 'not-an-email',
            '--password' => 'a-genuinely-long-password',
        ])->assertFailed();

        $this->assertSame(0, User::query()->where('is_admin', true)->count());
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        User::create([
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'email' => 'taken@example.test',
            'password' => Hash::make('whatever-password'),
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
        ]);

        $this->artisan('platform:create-owner', [
            '--email' => 'taken@example.test',
            '--password' => 'a-genuinely-long-password',
        ])->assertFailed();

        $this->assertSame(0, User::query()->where('is_admin', true)->count());
    }
}
