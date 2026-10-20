<?php

namespace Tests\Feature\Appearance;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Appearance\Appearance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Each person's own Light / Dark / System preference: stored on the user (never
 * on a Business or Agency workspace), validated to the three supported values,
 * rendered into the shell for that user only, and untouched by workspace
 * switching.
 */
class UserAppearancePreferenceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private function save(string $value)
    {
        return $this->postJson(route('user.account.appearance'), ['appearance' => $value]);
    }

    private function hubHtml(array $tenant): string
    {
        [, $business, $workspace] = $tenant;

        return $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();
    }

    private function htmlTag(string $html): string
    {
        preg_match('/<html[^>]*>/s', $html, $m);

        return $m[0] ?? '';
    }

    private function hasDarkClass(string $html): bool
    {
        return (bool) preg_match('/class="[^"]*\bdark-layout\b[^"]*"/', $this->htmlTag($html));
    }

    public function test_each_supported_value_is_saved_on_the_user_and_read_back(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);

        foreach (['dark', 'system', 'light'] as $value) {
            $this->save($value)->assertOk()->assertJson(['status' => 'success', 'appearance' => $value]);

            $this->assertSame($value, $tenant[0]->user->fresh()->appearance);
            $this->assertSame($value, Appearance::forUser($tenant[0]->user->fresh()));
        }
    }

    public function test_unsupported_values_are_rejected_and_nothing_is_stored(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);
        $this->save('dark')->assertOk();

        foreach (['purple', 'DARK', 'Dark', 'auto', '', '<script>alert(1)</script>', str_repeat('a', 300)] as $bad) {
            $this->save($bad)->assertStatus(422);
            $this->assertSame('dark', $tenant[0]->user->fresh()->appearance, 'rejected input must not change the stored value');
        }

        $this->postJson(route('user.account.appearance'), [])->assertStatus(422);
        $this->postJson(route('user.account.appearance'), ['appearance' => ['dark']])->assertStatus(422);
        $this->assertSame('dark', $tenant[0]->user->fresh()->appearance);
    }

    public function test_a_guest_cannot_save_a_preference(): void
    {
        $this->postJson(route('user.account.appearance'), ['appearance' => 'dark'])->assertUnauthorized();
    }

    public function test_a_preference_belongs_to_one_user_only(): void
    {
        $first = $this->tenant(WorkspacePlanTier::Growth, 'First Studio', 'First WS');
        $second = $this->tenant(WorkspacePlanTier::Growth, 'Second Studio', 'Second WS');

        $this->authenticateAs($first[0]);
        $this->save('dark')->assertOk();

        $this->assertSame('dark', $first[0]->user->fresh()->appearance);
        $this->assertNull($second[0]->user->fresh()->appearance);
        $this->assertTrue($this->hasDarkClass($this->hubHtml($first)));

        // Another person on the same browser/server never inherits it.
        Auth::logout();
        $this->flushSession();
        $this->authenticateAs($second[0]);

        $this->assertFalse($this->hasDarkClass($this->hubHtml($second)));
        $this->assertNull($second[0]->user->fresh()->appearance);
    }

    public function test_the_preference_survives_signing_out_and_back_in(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);
        $this->save('dark')->assertOk();

        // A completely new session for the same account, as on another device.
        Auth::logout();
        $this->flushSession();
        $reloaded = User::findOrFail($tenant[0]->user->id);
        $this->assertSame('dark', $reloaded->appearance);
        $this->authenticateAs($tenant[0]->fresh());

        $this->assertTrue($this->hasDarkClass($this->hubHtml($tenant)));
    }

    public function test_switching_business_or_agency_workspace_keeps_the_preference(): void
    {
        $managed = $this->createAgencyManagedClient();
        $agency = $managed['agencyOwner'];

        $this->authenticateAs($agency);
        $this->save('dark')->assertOk();

        // Own Business, the Agency account frame, then a managed client's Business.
        $this->switchTo($managed['agencyWorkspace'], $managed['agencyBusiness'])->assertRedirect();
        $this->assertTrue($this->hasDarkClass($this->get(route('customer.workspaces.businesses.settings.show', [$managed['agencyWorkspace']->uid, $managed['agencyBusiness']->uid]))->assertOk()->getContent()));

        $this->switchToAccount($managed['agencyWorkspace']);
        $this->assertTrue($this->hasDarkClass($this->get(route('customer.workspaces.settings.show', $managed['agencyWorkspace']->uid))->assertOk()->getContent()));

        $this->assertSame('dark', $agency->user->fresh()->appearance, 'switching context must not rewrite the preference');
    }

    public function test_saving_writes_only_the_user_and_no_business_or_workspace_setting(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $businessBefore = $business->fresh()->getAttributes();
        $workspaceBefore = $workspace->fresh()->getAttributes();

        $this->save('dark')->assertOk();

        $this->assertSame($businessBefore, $business->fresh()->getAttributes());
        $this->assertSame($workspaceBefore, $workspace->fresh()->getAttributes());
    }

    public function test_an_existing_user_with_no_saved_preference_keeps_the_light_interface(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);

        $this->assertNull($tenant[0]->user->fresh()->appearance);
        $html = $this->hubHtml($tenant);

        $this->assertFalse($this->hasDarkClass($html));
        $this->assertStringNotContainsString('data-layout="dark-layout"', $this->htmlTag($html));
        $this->assertStringContainsString('var pref = "light";', $html);
        $this->assertMatchesRegularExpression('/<input type="radio" name="appearance" value="light"\s+checked>/', $html);
    }

    public function test_a_corrupt_stored_value_renders_as_light_rather_than_being_trusted(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);
        User::whereKey($tenant[0]->user->id)->update(['appearance' => 'neon"><script>']);
        $this->actingAs($tenant[0]->user->fresh());

        $html = $this->hubHtml($tenant);

        $this->assertFalse($this->hasDarkClass($html));
        $this->assertStringContainsString('var pref = "light";', $html);
        $this->assertStringNotContainsString('neon', $html);
    }

    public function test_dark_is_rendered_on_the_server_so_the_first_paint_is_already_dark(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);
        $this->save('dark')->assertOk();

        $html = $this->hubHtml($tenant);

        $this->assertTrue($this->hasDarkClass($html));
        $this->assertStringContainsString('data-layout="dark-layout"', $this->htmlTag($html));
        $this->assertStringContainsString('var pref = "dark";', $html);
        $this->assertMatchesRegularExpression('/<input type="radio" name="appearance" value="dark"\s+checked>/', $html);
    }

    public function test_system_is_resolved_in_the_browser_before_first_paint_and_follows_the_os(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);
        $this->save('system')->assertOk();

        $html = $this->hubHtml($tenant);

        // The server cannot know the OS setting, so it adds no dark class itself...
        $this->assertFalse($this->hasDarkClass($html));
        $this->assertStringContainsString('var pref = "system";', $html);
        // ...the synchronous head script resolves it and listens for OS changes.
        $this->assertStringContainsString("matchMedia('(prefers-color-scheme: dark)')", $html);
        $this->assertStringContainsString("query.addEventListener('change'", $html);
        $this->assertLessThan(strpos($html, '</head>'), strpos($html, 'var pref = "system";'), 'resolved inside <head>, before the body paints');
        $this->assertLessThan(strpos($html, 'rel="stylesheet"'), strpos($html, 'var pref = "system";'), 'resolved before any stylesheet is applied');
        $this->assertStringContainsString('name="appearance" value="system"', $html);
    }

    public function test_the_preference_is_never_cached_in_browser_storage(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);
        $html = $this->hubHtml($tenant);

        $this->assertStringNotContainsString('localStorage', $html);
        $this->assertStringNotContainsString('sessionStorage', $html);
    }

    public function test_settings_still_has_one_of_each_heading_and_gains_a_single_appearance_control(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($tenant[0]);
        $html = $this->hubHtml($tenant);

        $this->assertSame(1, substr_count($html, '<h1 class="mg-settings-title">'));
        $this->assertSame(1, substr_count($html, 'data-role="settings-appearance"'));
        $this->assertSame(1, substr_count($html, 'data-role="settings-account-link"'));
        $this->assertSame(1, substr_count($html, 'Your account</h2>'));
        $this->assertSame(3, substr_count($html, 'name="appearance"'));
        $this->assertSame(10, substr_count($html, 'data-role="settings-module"'));
        $this->assertStringContainsString('data-role="settings-profile-language"', $html);
        $this->assertStringContainsString('role="radiogroup"', $html);
        $this->assertStringContainsString(route('user.account.appearance'), $html);
    }

    public function test_the_agency_account_settings_hub_offers_the_same_personal_control(): void
    {
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);
        $this->switchToAccount($workspace);

        $html = $this->get(route('customer.workspaces.settings.show', $workspace->uid))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-role="settings-appearance"'));
        $this->assertSame(1, substr_count($html, '<h1 class="mg-settings-title">'));
    }

    public function test_profile_update_cannot_smuggle_in_an_appearance_value(): void
    {
        $tenant = $this->tenant(WorkspacePlanTier::Growth);
        $user = $tenant[0]->user;

        $this->assertNotContains('appearance', $user->getFillable(), 'only the dedicated, validated endpoint may write it');
    }
}
