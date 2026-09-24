<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards against a hardcoded default administrator account being
 * reintroduced by a revert or a copy-paste. `UserSeeder` used to insert one
 * with a fixed vendor email and a fixed (or console-printed) password on
 * every fresh install; `platform:create-owner` is now the only supported way
 * to create the first owner, and it always requires an operator-supplied
 * email and password.
 */
class NoHardcodedDefaultAdministratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_user_seeder_creates_no_administrator_account(): void
    {
        (new UserSeeder())->run();

        $this->assertSame(0, User::query()->where('is_admin', true)->count());
    }

    public function test_the_user_seeder_still_seeds_the_administrator_role_and_its_permissions(): void
    {
        (new UserSeeder())->run();

        $this->assertDatabaseHas('roles', ['name' => 'administrator']);
        $this->assertGreaterThan(0, \App\Models\Role::where('name', 'administrator')->first()->permissions()->count());
    }

    /**
     * Scoped to account-creation code (seeders, the owner-creation command,
     * config defaults), not the whole app/ tree: AppConfig's `from_email`/
     * `notification_email` seed defaults also carry this vendor address, but
     * that is a separate outgoing-mail-footprint concern, not an
     * administrator-account credential, and is out of this fix's scope.
     */
    public function test_no_account_creation_source_file_declares_the_former_vendor_default_administrator_email(): void
    {
        $matches = [];

        $scopedFiles = [
            app_path('Console/Commands/CreatePlatformOwnerCommand.php'),
            config_path('app.php'),
        ];

        foreach ($scopedFiles as $path) {
            $contents = (string) file_get_contents($path);

            if (str_contains($contents, 'akasham67@gmail.com')) {
                $matches[] = $path;
            }
        }

        $databaseIterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(database_path('seeders'), \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($databaseIterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (str_contains($contents, 'akasham67@gmail.com')) {
                $matches[] = $file->getPathname();
            }
        }

        $this->assertSame([], $matches, 'No account-creation source file may hardcode the former vendor default administrator email.');
    }

    public function test_ordinary_signup_never_sets_is_admin_true(): void
    {
        $source = (string) file_get_contents(app_path('Http/Controllers/Auth/V1SignupController.php'));

        $this->assertStringNotContainsString(
            "'is_admin'",
            $source,
            'The canonical V1 customer signup path must never assign an is_admin flag.',
        );
    }
}
