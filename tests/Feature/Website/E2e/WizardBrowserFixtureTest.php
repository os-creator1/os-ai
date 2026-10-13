<?php

namespace Tests\Feature\Website\E2e;

use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * NOT a regression test. A fixture seeder for the browser walkthrough in
 * tests/e2e/ (see tests/e2e/README.md): it COMMITS one disposable customer,
 * workspace and Photo Booth business to the (disposable, TestDatabaseSafety-
 * approved) test database so a real browser can log in, and writes the
 * credentials to a file. It is skipped unless WIZARD_E2E_SEED is set, so it
 * is inert in every normal and CI run.
 */
class WizardBrowserFixtureTest extends TestCase
{
    use CreatesWebsiteFixtures;

    public function test_seed_browser_fixture(): void
    {
        if (! getenv('WIZARD_E2E_SEED')) {
            $this->markTestSkipped('Browser fixture seeding is opt-in (WIZARD_E2E_SEED=1).');
        }

        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);

        $overrides = json_decode((string) getenv('WIZARD_E2E_BUSINESS'), true) ?: [];
        [$customer, $business, $workspace] = $this->entitledTenant($overrides);

        $location = json_decode((string) getenv('WIZARD_E2E_LOCATION'), true);
        if (is_array($location)) {
            $existing = $business->primaryLocation()->first();
            if ($existing !== null) {
                $existing->forceFill($location)->save();
            } else {
                $created = $business->locations()->create($location + ['name' => 'Primary Location', 'service_mode' => 'service_area', 'country_code' => 'US']);
                $created->forceFill(['is_primary' => true])->save();
            }
        }

        $password = 'E2e-Fixture-Pass-123!';
        // What a real login reads into the session (see EloquentAccountRepository::redirectAfterLogin()).
        $customer->forceFill(['permissions' => \App\Models\Customer::customerPermissions()])->save();
        $customer->user->forceFill([
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ])->save();

        file_put_contents((string) getenv('WIZARD_E2E_OUT'), json_encode([
            'email' => $customer->user->email,
            'password' => $password,
            'workspace' => $workspace->uid,
            'business' => $business->uid,
        ]));

        $this->assertTrue(true);
    }
}
