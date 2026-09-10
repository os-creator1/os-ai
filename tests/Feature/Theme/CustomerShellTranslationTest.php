<?php

namespace Tests\Feature\Theme;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Navigation\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 2 — T-I18N-1 and T-I18N-2 (contract §17.1,
 * §24; brief §7). A representative matrix of rendered customer pages —
 * Business and Account frames, Core/Growth and Agency owners, scoped
 * staff, View-as — contains the literal substring `locale.` nowhere;
 * every label the navigation builder can emit has an English entry; and
 * a label without one still renders as the builder's human label, never
 * as a key path.
 */
class CustomerShellTranslationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    /**
     * Labels the builder computes at runtime rather than writing literally.
     */
    private const DYNAMIC_LABELS = ['Team & account', 'Team & agency account', 'Choose an account', 'Businesses', 'Client accounts', 'Business', 'Client account'];

    /**
     * @return array<string, string> label => rendered HTML
     */
    private function businessFramePages(string $workspaceUid, string $businessUid): array
    {
        $pages = [
            'home' => route('user.home'),
            'contacts' => route('customer.workspaces.businesses.contacts.index', [$workspaceUid, $businessUid]),
            'campaigns' => route('customer.workspaces.businesses.outreach.campaigns', [$workspaceUid, $businessUid]),
            'automations' => route('customer.workspaces.businesses.automations.index', [$workspaceUid, $businessUid]),
            'website' => route('customer.workspaces.businesses.website.show', [$workspaceUid, $businessUid]),
            'gbp' => route('customer.workspaces.businesses.gbp.index', [$workspaceUid, $businessUid]),
            'analytics' => route('customer.workspaces.businesses.analytics.overview', [$workspaceUid, $businessUid]),
            'usage-billing' => route('customer.workspaces.businesses.usage-billing.show', [$workspaceUid, $businessUid]),
        ];

        $html = [];

        foreach ($pages as $label => $url) {
            // A module entry may hand over to its own first-run page (a
            // redirect inside the customer shell); the rendered destination
            // is what the customer reads, so follow it. A module the plan
            // tier is not entitled to answers 404 by the disclosure rule and
            // renders no customer page at all.
            $response = $this->followingRedirects()->get($url);

            if ($response->getStatusCode() === 404 && in_array($label, ['website', 'gbp', 'automations'], true)) {
                continue;
            }

            $response->assertOk();
            $html[$label] = $response->getContent();
        }

        $this->assertGreaterThanOrEqual(5, count($html), 'The core Business-frame pages must render.');

        return $html;
    }

    private function assertNoRawKey(array $pages, string $actor): void
    {
        foreach ($pages as $label => $html) {
            $this->assertStringNotContainsString('locale.', $html, "{$actor}: {$label} renders a raw translation key.");
        }
    }

    public function test_core_and_growth_owners_never_see_a_raw_translation_key(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$owner, $business, $workspace] = $this->tenant($tier, 'Harbor Lane Studios', 'Harbor Lane');
            $this->authenticateAs($owner);

            $pages = $this->businessFramePages($workspace->uid, $business->uid);
            $pages['account-list'] = $this->get(route('customer.workspaces.index'))->assertOk()->getContent();
            $pages['account'] = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();

            $this->assertNoRawKey($pages, $tier->value . ' owner');
        }
    }

    public function test_agency_owner_admin_and_scoped_staff_never_see_a_raw_translation_key(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $second = $this->addBusiness($owner, $workspace, 'Client Two');

        $this->authenticateAs($owner);
        $pages = $this->businessFramePages($workspace->uid, $business->uid);
        $pages['account-frame'] = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();
        $pages['account-list'] = $this->get(route('customer.workspaces.index'))->assertOk()->getContent();
        $this->assertNoRawKey($pages, 'agency owner');

        $admin = $this->createCustomer();
        $this->member($workspace, $admin->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($admin);
        $this->assertNoRawKey([
            'home' => $this->home()->assertOk()->getContent(),
            'account-frame' => $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent(),
        ], 'agency admin');

        $staff = $this->createCustomer();
        $this->assign($this->member($workspace, $staff->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::Selected), $second);
        $this->authenticateAs($staff);
        $this->assertNoRawKey([
            'home' => $this->home()->assertOk()->getContent(),
            'analytics' => $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $second->uid]))->assertOk()->getContent(),
            'contacts' => $this->get(route('customer.workspaces.businesses.contacts.index', [$workspace->uid, $second->uid]))->assertOk()->getContent(),
        ], 'scoped staff');
    }

    public function test_view_as_client_pages_never_see_a_raw_translation_key(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($owner, $workspace, 'Client Two');
        $this->authenticateAs($owner);
        $this->startViewAs($workspace, $business)->assertRedirect(route('user.home'));

        $pages = $this->businessFramePages($workspace->uid, $business->uid);
        $this->assertStringContainsString('data-role="view-as-banner"', $pages['home']);

        $this->assertNoRawKey($pages, 'view-as');
    }

    public function test_profile_and_authentication_screens_never_see_a_raw_translation_key(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);
        $owner->notifications = json_encode(['login' => 'yes', 'sender_id' => 'yes', 'keyword' => 'yes', 'subscription' => 'yes', 'promotion' => 'yes']);
        $owner->save();
        $this->authenticateAs($owner);

        $this->assertNoRawKey(['profile' => $this->get(route('user.account'))->assertOk()->getContent()], 'profile');

        auth()->logout();

        $this->assertNoRawKey([
            'login' => $this->get(route('login'))->assertOk()->getContent(),
            'forgot' => $this->get(route('password.request'))->assertOk()->getContent(),
            'two-factor' => $this->get(route('verify.index'))->assertOk()->getContent(),
        ], 'guest');
    }

    /**
     * T-I18N-2: every label the builder can emit — literal or computed —
     * has an English entry under locale.menu.
     */
    public function test_every_navigation_label_has_an_english_translation(): void
    {
        $source = file_get_contents(base_path('app/Library/Navigation/CustomerMenuBuilder.php'));

        preg_match_all("/\\\$this->item\\(\\\$user, '[^']+', '([^']+)'/", $source, $literal);
        preg_match_all("/new MenuItem\\('[^']+', '([^']+)'/", $source, $groups);

        $labels = array_values(array_unique(array_merge($literal[1], $groups[1], self::DYNAMIC_LABELS)));

        $this->assertGreaterThanOrEqual(25, count($labels), 'The inventory covers the whole builder.');

        foreach (['Website', 'Google Business Profile', 'Messaging provider', 'Prospecting', 'Campaigns', 'Conversations', 'Automations', 'Analytics', 'Usage & billing', 'Client accounts', 'Settings', 'Developers', 'Advanced', 'Plan & subscription'] as $required) {
            $this->assertContains($required, $labels, "The builder no longer emits {$required}; update the inventory.");
        }

        foreach ($labels as $label) {
            $this->assertTrue(Lang::has('locale.menu.' . $label, 'en'), "No English entry for navigation label \"{$label}\".");
            $this->assertNotSame('', trim((string) __('locale.menu.' . $label, [], 'en')));
        }

        foreach (['Profile', 'Sign out', 'Plan & subscription'] as $userMenuLabel) {
            $this->assertTrue(Lang::has('locale.menu.' . $userMenuLabel, 'en'));
        }

        $this->assertSame('Sign out', __('locale.menu.Logout', [], 'en'), 'The user menu signs out in plain words.');
    }

    /**
     * T-I18N-2: a label with no translation renders as the trusted human
     * label the builder supplied, and the key path never appears.
     */
    public function test_a_missing_translation_falls_back_to_the_human_label_never_the_key_path(): void
    {
        $item = new MenuItem('zebra', 'Zebra Widgets', '/zebra', 'box', false);

        $this->assertFalse(Lang::has('locale.menu.Zebra Widgets'));

        $html = Blade::render('<x-customer-nav-item :item="$item" />', ['item' => $item]);

        $this->assertStringContainsString('Zebra Widgets', $html);
        $this->assertStringNotContainsString('locale.', $html);

        $translated = new MenuItem('gbp', 'Google Business Profile', '/gbp', 'map-pin', false);
        $this->assertStringContainsString('Google Business Profile', Blade::render('<x-customer-nav-item :item="$item" />', ['item' => $translated]));
    }

    /**
     * Customer Experience Redesign Slice 1A (Correction 3, contract §5/§6a
     * #1): the exact 17 new keys this slice adds all resolve, exhaustively
     * — not merely the ones CustomerMenuBuilder happens to emit.
     */
    public function test_all_seventeen_new_locale_keys_resolve(): void
    {
        $menuKeys = [
            'Platform Settings', 'Theme Presets', 'Usage Billing', 'Safety Limits',
            'Provider Events', 'Additional Slot Agreements', 'Workspace', 'Workspace Plans',
            'Opportunities', 'Messaging', 'Sender identities',
        ];

        foreach ($menuKeys as $key) {
            $this->assertTrue(Lang::has('locale.menu.' . $key, 'en'), "Missing new menu key: {$key}");
            $this->assertNotSame('', trim((string) __('locale.menu.' . $key, [], 'en')));
        }

        $permissionKeys = ['website', 'read_google_business_profile', 'manage_google_business_profile', 'manage_advanced_provider'];

        foreach ($permissionKeys as $key) {
            $this->assertTrue(Lang::has('locale.permission.' . $key, 'en'), "Missing new permission key: {$key}");
            $this->assertNotSame('', trim((string) __('locale.permission.' . $key, [], 'en')));
        }

        $this->assertSame('Messaging provider', __('locale.labels.messaging_provider', [], 'en'));
        $this->assertSame('Sender identity', __('locale.labels.sender_identity', [], 'en'));
    }

    /**
     * Customer Experience Redesign Slice 1A (Correction 3, contract §4b):
     * the exact 16 existing delegated-access locale values this slice
     * corrects now read "Team member(s)", never "Sub Account(s)"/
     * "Sub-Account(s)" — checked directly against the locale file, in
     * addition to the rendered-page coverage in
     * Tests\Feature\SubAccounts\DelegatedAccessTerminologyTest.
     */
    public function test_all_sixteen_corrected_delegated_access_values_say_team_member(): void
    {
        $forbidden = '/sub[\s-]?account/i';

        // Two of the sixteen — invitation.subject/invitation.body — are
        // literal array keys that themselves contain a dot
        // ('invitation.subject' => ..., not a nested ['invitation' =>
        // ['subject' => ...]] array). Laravel's __()/trans() dot-notation
        // cannot address a literal dotted key, so it is read directly off
        // the loaded 'sub_accounts' array instead — this is a pre-existing
        // property of the array shape, unrelated to and unchanged by this
        // slice, which only rewrites the two values, never the key shape.
        $subAccounts = Lang::get('locale.sub_accounts', [], 'en');
        $this->assertIsArray($subAccounts);

        $correctedKeys = [
            'labels.sub_accounts' => 'Team members',
            'sub_accounts.add_new' => 'Add team member',
            'sub_accounts.update_sub_account' => 'Update team member',
            'sub_accounts.sub_account_added' => 'Team member successfully added',
            'sub_accounts.sub_account_updated' => 'Team member successfully updated',
            'sub_accounts.sub_account_deleted' => 'Team member successfully deleted',
            'sub_accounts.sub_account_status_updated' => 'Team member status successfully updated',
            'sub_accounts.enable_selected_sub_accounts' => 'Are you sure you want to enable the selected team members?',
            'sub_accounts.disable_selected_sub_accounts' => 'Are you sure you want to disable the selected team members?',
            'sub_accounts.delete_selected_sub_accounts' => 'Are you sure you want to delete the selected team members?',
            'sub_accounts.sub_accounts_enabled' => 'Selected team members enabled',
            'sub_accounts.sub_accounts_disabled' => 'Selected team members disabled',
            'sub_accounts.sub_accounts_deleted' => 'Selected team members deleted',
            'sub_accounts.login_as_parent_message' => 'You are currently logged in as a team member. You can manage the main account below.',
        ];

        $this->assertCount(14, $correctedKeys);

        foreach ($correctedKeys as $key => $expected) {
            $actual = __('locale.' . $key, [], 'en');
            $this->assertSame($expected, $actual, "locale.{$key} does not read the corrected value.");
            $this->assertDoesNotMatchRegularExpression($forbidden, $actual, "locale.{$key} still contains a forbidden Sub-Account variant.");
        }

        $dottedKeys = [
            'invitation.subject' => 'You are invited to join :app_name as a team member',
            'invitation.body' => 'You have been invited to join as a team member. Please click the button below to accept the invitation and set up your password.',
        ];

        foreach ($dottedKeys as $key => $expected) {
            $this->assertArrayHasKey($key, $subAccounts, "locale.sub_accounts.{$key} is missing.");
            $this->assertSame($expected, $subAccounts[$key], "locale.sub_accounts.{$key} does not read the corrected value.");
            $this->assertDoesNotMatchRegularExpression($forbidden, $subAccounts[$key], "locale.sub_accounts.{$key} still contains a forbidden Sub-Account variant.");
        }

        // 14 + 2 dotted = 16 existing values corrected, exactly.
        $this->assertCount(16, array_merge($correctedKeys, $dottedKeys));

        // The nine untouched values keep their exact wording — no
        // unrelated locale cleanup (contract §6a #1).
        $untouched = [
            'sub_accounts.add_password' => 'Add Password',
            'sub_accounts.send_invitation' => 'Send Invitation',
            'sub_accounts.accept_invitation' => 'Accept Invitation',
            'sub_accounts.active_account' => 'Active Account',
            'sub_accounts.sub_account_activated' => 'Your account is now active.',
            'sub_accounts.accept_invitation_description' => 'Please enter your password to accept the invitation',
            'sub_accounts.manage_account' => 'Manage Account',
            'sub_accounts.login_as_parent' => 'Login as Parent',
        ];

        $this->assertCount(8, $untouched);

        foreach ($untouched as $key => $expected) {
            $this->assertSame($expected, __('locale.' . $key, [], 'en'), "locale.{$key} must not be touched by this slice.");
        }

        $this->assertArrayHasKey('invitation.footer', $subAccounts);
        $this->assertSame('If you didn’t expect this invitation, you can safely ignore this email.', $subAccounts['invitation.footer']);

        // 8 + 1 dotted = 9 untouched values, exactly.
        $this->assertCount(9, array_merge($untouched, ['invitation.footer' => $subAccounts['invitation.footer']]));
    }
}
