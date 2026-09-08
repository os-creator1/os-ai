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
}
