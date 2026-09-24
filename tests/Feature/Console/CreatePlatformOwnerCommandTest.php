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
 * already exists), the fail-closed guard when the `administrator` role is
 * missing, and the validation that keeps every owner's credential genuinely
 * operator-supplied — entered only through the concealed prompt, never a
 * command-line argument.
 */
class CreatePlatformOwnerCommandTest extends TestCase
{
    use RefreshDatabase;

    private function seedAdministratorRole(): Role
    {
        return Role::create(['name' => 'administrator', 'status' => true]);
    }

    public function test_fresh_installation_creates_exactly_one_administrator(): void
    {
        $this->seedAdministratorRole();

        $this->artisan('platform:create-owner', ['--email' => 'owner@example.test'])
            ->expectsQuestion('Owner password', 'a-genuinely-long-password')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->where('is_admin', true)->count());

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue($owner->is_admin);
        $this->assertFalse((bool) $owner->is_customer);
        $this->assertTrue(Hash::check('a-genuinely-long-password', $owner->password));
    }

    public function test_it_attaches_the_administrator_role(): void
    {
        $role = $this->seedAdministratorRole();

        $this->artisan('platform:create-owner', ['--email' => 'owner@example.test'])
            ->expectsQuestion('Owner password', 'a-genuinely-long-password')
            ->assertSuccessful();

        $owner = User::query()->where('email', 'owner@example.test')->firstOrFail();

        $this->assertTrue($owner->roles()->whereKey($role->id)->exists());
    }

    public function test_it_fails_closed_when_the_administrator_role_does_not_exist(): void
    {
        $this->artisan('platform:create-owner', ['--email' => 'owner@example.test'])
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
    }

    public function test_repeat_execution_without_force_refuses_and_creates_no_duplicate(): void
    {
        $this->seedAdministratorRole();

        $this->artisan('platform:create-owner', ['--email' => 'owner@example.test'])
            ->expectsQuestion('Owner password', 'a-genuinely-long-password')
            ->assertSuccessful();

        $this->artisan('platform:create-owner', ['--email' => 'second-owner@example.test'])
            ->assertFailed();

        $this->assertSame(1, User::query()->where('is_admin', true)->count());
        $this->assertDatabaseMissing('users', ['email' => 'second-owner@example.test']);
    }

    public function test_force_allows_creating_an_additional_owner(): void
    {
        $this->seedAdministratorRole();

        $this->artisan('platform:create-owner', ['--email' => 'owner@example.test'])
            ->expectsQuestion('Owner password', 'a-genuinely-long-password')
            ->assertSuccessful();

        $this->artisan('platform:create-owner', [
            '--email' => 'second-owner@example.test',
            '--force' => true,
        ])
            ->expectsQuestion('Owner password', 'another-long-password')
            ->assertSuccessful();

        $this->assertSame(2, User::query()->where('is_admin', true)->count());
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->seedAdministratorRole();

        $this->artisan('platform:create-owner', ['--email' => 'owner@example.test'])
            ->expectsQuestion('Owner password', 'short')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'owner@example.test']);
    }

    public function test_an_invalid_email_is_refused(): void
    {
        $this->seedAdministratorRole();

        $this->artisan('platform:create-owner', ['--email' => 'not-an-email'])
            ->expectsQuestion('Owner password', 'a-genuinely-long-password')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('is_admin', true)->count());
    }

    public function test_an_email_already_in_use_is_refused(): void
    {
        $this->seedAdministratorRole();

        User::create([
            'first_name' => 'Existing',
            'last_name' => 'Customer',
            'email' => 'taken@example.test',
            'password' => Hash::make('whatever-password'),
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
        ]);

        $this->artisan('platform:create-owner', ['--email' => 'taken@example.test'])
            ->expectsQuestion('Owner password', 'a-genuinely-long-password')
            ->assertFailed();

        $this->assertSame(0, User::query()->where('is_admin', true)->count());
    }

    public function test_the_signature_carries_no_password_option(): void
    {
        $source = (string) file_get_contents(app_path('Console/Commands/CreatePlatformOwnerCommand.php'));

        $this->assertStringNotContainsString('--password', $source, 'The password must never be a command-line option.');
    }
}
