<?php

namespace Tests\Feature\CustomerShell;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Branding\AgencyBrand;
use App\Library\Branding\AgencyBrandSource;
use App\Library\Branding\BrandingPresenter;
use App\Library\Navigation\BusinessCandidate;
use App\Library\Navigation\WorkspaceCandidate;
use App\Models\Announcements;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer shell, account and profile cleanup — the logged-in walkthrough.
 *
 *  1. Profile opens for a normal Business owner (it crashed on a missing
 *     notification preference key).
 *  2. Core and Growth work inside their Business: no Account Home that asks
 *     them to choose their only Business, the account is Settings → Account,
 *     and legitimate multi-account switching still works. Agency unchanged.
 *  3. The user dropdown is Profile, Product updates, Sign out.
 *  4. Product updates is read-only and shows only this person's updates.
 *  5. Team is under Settings, for the account holder only.
 *  6. The footer names the active brand with normal copyright wording.
 *  7. A missing profile photo is initials, never a broken image.
 */
class CustomerShellAccountProfileCleanupTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget(BrandingPresenter::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Cache::forget(BrandingPresenter::CACHE_KEY);

        parent::tearDown();
    }

    // =================================================================
    // 1. Profile
    // =================================================================

    public function test_a_business_owner_with_no_stored_notification_preferences_can_open_profile(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Jazmin Media');
        $this->assertNull($customer->fresh()->notifications, 'Precondition: a customer created with a Business stores no preferences.');
        $this->authenticateAs($customer);

        $html = $this->get(route('user.account'))->assertOk()->getContent();

        // The preferences render with the defaults a new customer gets.
        $this->assertMatchesRegularExpression('/name="notifications\[login\]"[^>]*id="login"\s*>/', $html, 'Login notices start off.');
        $this->assertMatchesRegularExpression('/name="notifications\[sender_id\]"[^>]*checked/', $html, 'Sender ID notices start on.');
    }

    public function test_a_partial_or_unreadable_preference_set_never_crashes_and_keeps_what_was_chosen(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);

        foreach ([json_encode(['login' => 'yes']), '{not json', ''] as $stored) {
            DB::table('customers')->where('id', $customer->id)->update(['notifications' => $stored]);
            $fresh = Customer::query()->findOrFail($customer->id);

            $preferences = $fresh->getNotifications();

            $this->assertSame(array_keys(Customer::DEFAULT_NOTIFICATIONS), array_keys($preferences), 'Every key, always.');
            $this->assertSame(str_contains($stored, 'login') ? 'yes' : 'no', $preferences['login'], 'A stored choice wins over the default.');
        }

        $this->authenticateAs($customer);
        $this->get(route('user.account'))->assertOk();
    }

    // =================================================================
    // 2. Core / Growth: the Business is the working context
    // =================================================================

    public function test_core_and_growth_are_never_offered_an_account_home_hop(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth] as $tier) {
            [$customer, $business, $workspace] = $this->tenant($tier, 'Harbor Lane ' . $tier->value, 'Jazmin Media ' . $tier->value);
            $this->authenticateAs($customer);

            $home = $this->home()->assertOk()->getContent();
            $shell = $this->shellHtml($home);

            $this->assertStringContainsString('data-kind="business"', $home, "[{$tier->value}] lands on the Business Home.");
            $this->assertStringNotContainsString('data-role="context-option-account"', $shell, "[{$tier->value}] no account frame to hop into.");
            $this->assertStringNotContainsString('Choose a business', $home);
            $this->assertStringNotContainsStringIgnoringCase('workspace', $this->shellText($home), 'Never "Workspace".');

            // Superseded by the walkthrough settings decision: the account is
            // not a customer-managed object. Its billing, plan and team are
            // modules of the Business's Settings, and the account page opens there.
            $hub = route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]);
            $this->assertStringNotContainsString('href="' . route('customer.workspaces.show', $workspace->uid) . '"', $shell);
            $this->assertStringNotContainsString('Account settings', $shell);
            $this->assertContains($hub, $this->menuLinks($home), 'Settings.');
            $modules = $this->settingsHubModuleKeys($this->get($hub)->assertOk()->getContent());
            foreach (['team', 'plan', 'usage-billing'] as $module) {
                $this->assertContains($module, $modules, "Settings → [{$module}].");
            }
            $this->get(route('customer.workspaces.show', $workspace->uid))->assertRedirect($hub);

            auth()->logout();
            $this->flushSession();
        }
    }

    /** A remembered or forged "account" choice falls straight through to the Business. */
    public function test_an_account_frame_choice_for_a_core_account_lands_on_its_business_not_a_chooser(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core, 'Harbor Lane Studios', 'Jazmin Media');
        $this->authenticateAs($customer);

        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));

        $home = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-kind="business"', $home);
        $this->assertStringNotContainsString('data-kind="chooser"', $home);
        $this->assertStringContainsString('Business navigation', $home);

        // And it stays that way on the next request.
        $this->assertStringContainsString('data-kind="business"', $this->home()->assertOk()->getContent());
    }

    public function test_a_member_of_two_accounts_still_switches_between_them_by_their_businesses(): void
    {
        [$customer, $ownBusiness, $ownWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Jazmin Media');
        [, $hostBusiness, $hostWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Northwind Bakery', 'Northwind Group');
        $this->member($hostWorkspace, $customer->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All);
        $this->authenticateAs($customer);

        $shell = $this->shellHtml($this->home()->assertOk()->getContent());
        $this->assertSame(2, substr_count($shell, 'data-role="context-option-business"'), 'Both Businesses, each named by its account.');
        $this->assertStringContainsString('Jazmin Media', $shell);
        $this->assertStringContainsString('Northwind Group', $shell);
        $this->assertStringNotContainsString('data-role="context-option-account"', $shell);

        $this->switchTo($hostWorkspace, $hostBusiness)->assertRedirect(route('user.home'));
        $this->assertStringContainsString('Northwind Bakery', $this->shellText($this->home()->assertOk()->getContent()));

        $this->switchTo($ownWorkspace, $ownBusiness)->assertRedirect(route('user.home'));
        $this->assertStringContainsString('Harbor Lane Studios', $this->shellText($this->home()->assertOk()->getContent()));
    }

    public function test_the_agency_account_home_and_client_businesses_are_unaffected(): void
    {
        [$agency, $clientOne, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->authenticateAs($agency);

        // The account holds one Business, so its Agency Account Home is the
        // deliberate move the switcher offers.
        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));
        $start = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-kind="agency"', $start, 'Agency Account Home.');
        $this->assertSame(1, substr_count($this->shellHtml($start), 'data-role="context-option-account"'));

        $this->switchTo($workspace, $clientOne)->assertRedirect(route('user.home'));
        $inClient = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-kind="business"', $inClient);
        $this->assertStringContainsString('Client One', $this->shellText($inClient));

        $this->switchToAccount($workspace)->assertRedirect(route('user.home'));
        $this->assertStringContainsString('data-kind="agency"', $this->home()->assertOk()->getContent(), 'And back to the portfolio.');
    }

    public function test_only_a_portfolio_or_an_account_with_nothing_to_open_has_its_own_home(): void
    {
        $candidate = fn (WorkspacePlanTier $tier, array $businesses, bool $owner = true, ?WorkspaceBusinessAccessScope $scope = null) => new WorkspaceCandidate(
            1, 'uid', 'Account', true, 1, $owner, $owner ? null : WorkspaceMembershipRole::Staff, $scope, ! $owner, $tier, $tier->value, $businesses,
        );
        $business = new BusinessCandidate(1, 'b-uid', 'Harbor Lane', \App\Enums\Business\BusinessStatus::Active->value, 1, true, true, 'uid', 'Account');

        $this->assertTrue($candidate(WorkspacePlanTier::Agency, [$business])->hasAccountHome(), 'An Agency portfolio.');
        $this->assertFalse($candidate(WorkspacePlanTier::Core, [$business])->hasAccountHome(), 'Core with its Business: a hop.');
        $this->assertFalse($candidate(WorkspacePlanTier::Growth, [$business])->hasAccountHome(), 'Growth with its Business: a hop.');
        $this->assertTrue($candidate(WorkspacePlanTier::Growth, [])->hasAccountHome(), 'Nothing to open yet: the account frame is the way in.');
        $this->assertFalse($candidate(WorkspacePlanTier::Agency, [$business], false, WorkspaceBusinessAccessScope::Selected)->hasAccountHome(), 'Never for a selected-scope member.');
    }

    // =================================================================
    // 3. The user dropdown
    // =================================================================

    public function test_the_user_dropdown_is_profile_product_updates_and_sign_out(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $dropdown = $this->userDropdownHtml($html);

        preg_match_all('/<a class="dropdown-item"[^>]*href="([^"]+)"/', $dropdown, $links);
        $this->assertSame([route('user.account'), route('user.account.announcement'), route('logout')], $links[1]);

        $text = html_entity_decode(strip_tags($dropdown));
        foreach (['Profile', 'Product updates', 'Sign out'] as $label) {
            $this->assertStringContainsString($label, $text);
        }
        foreach (['Plan & subscription', 'Team members', 'Announcements'] as $moved) {
            $this->assertStringNotContainsString($moved, $text, "[{$moved}] is not a personal menu item.");
        }

        // Each moved destination is a Settings module, never a personal menu item.
        $this->assertStringNotContainsString(route('customer.sub_accounts.index'), $dropdown);
        $this->assertStringNotContainsString('/team', $dropdown);
        $this->assertStringNotContainsString('/plan', $dropdown);
    }

    // =================================================================
    // 4. Product updates — read-only
    // =================================================================

    public function test_product_updates_lists_only_this_persons_updates_read_only_and_newest_first(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        [$stranger] = $this->tenant(WorkspacePlanTier::Growth, 'Stranger Bakery', 'Stranger Group');

        $older = $this->publish('Scheduling is here', [$customer->user], now()->subDays(3));
        $newer = $this->publish('Faster inbox', [$customer->user], now()->subDay());
        $this->publish('Only for someone else', [$stranger->user]);
        $newer->users()->updateExistingPivot($customer->user_id, ['read_at' => now()]);

        $this->authenticateAs($customer);
        $page = $this->get(route('user.account.announcement'))->assertOk();
        $list = $this->between($page->getContent(), 'data-role="product-updates"', '</section>');

        $this->assertLessThan(strpos($list, 'Scheduling is here'), strpos($list, 'Faster inbox'), 'Newest first.');
        $this->assertStringNotContainsString('Only for someone else', $page->getContent());
        $this->assertMatchesRegularExpression('/data-state="unread".*Scheduling is here.*New/s', $list);
        $this->assertMatchesRegularExpression('/data-state="read">\s*<a[^>]*>\s*<span[^>]*>Faster inbox/s', $list);

        // Nothing but reading: no actions menu, selection, or management verbs.
        foreach (['Actions', 'type="checkbox"', 'Delete', 'Edit', 'Create', 'mark_as_read', 'batch'] as $management) {
            $this->assertStringNotContainsString($management, $list, "[{$management}] is not a reader's control.");
        }
        $page->assertSee('Product updates');
        $this->assertStringContainsString('href="' . route('user.account.announcement.view', $older->uid) . '"', $list);
    }

    public function test_opening_an_update_marks_it_read_once_and_only_ones_own_update_opens(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        [$stranger] = $this->tenant(WorkspacePlanTier::Growth, 'Stranger Bakery', 'Stranger Group');
        $mine = $this->publish('Scheduling is here', [$customer->user]);
        $theirs = $this->publish('Only for someone else', [$stranger->user]);

        $this->authenticateAs($customer);

        $this->get(route('user.account.announcement.view', $mine->uid))->assertOk()->assertSee('Scheduling is here');
        $firstRead = $this->readAt($mine, $customer->user);
        $this->assertNotNull($firstRead, 'Opening it is reading it.');

        $this->travel(2)->hours();
        $this->get(route('user.account.announcement.view', $mine->uid))->assertOk();
        $this->assertSame((string) $firstRead, (string) $this->readAt($mine, $customer->user), 'The first read time is kept.');

        // Someone else's update, its numeric id, or nothing at all: the same 404.
        $this->get(route('user.account.announcement.view', $theirs->uid))->assertNotFound();
        $this->get(route('user.account.announcement.view', (string) $theirs->id))->assertNotFound();
        $this->get(route('user.account.announcement.view', (string) Str::uuid()))->assertNotFound();
        $this->assertNull($this->readAt($theirs, $stranger->user), 'Nothing about the other update changed.');
    }

    public function test_no_announcement_management_is_reachable_from_the_customer_side(): void
    {
        foreach (['search', 'batch_action', 'mark-as-read', 'mark-all-as-read'] as $removed) {
            $this->assertFalse(Route::has('user.account.announcement.' . $removed), "[{$removed}] is gone from the customer side.");
        }

        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        foreach (['announcement/search', 'announcements/batch_action', 'announcements/mark-as-read', 'announcements/mark-all-as-read'] as $uri) {
            $this->assertContains($this->post('/' . $uri)->getStatusCode(), [404, 405], "POST /{$uri} does nothing.");
        }

        // The publisher's surface stays the platform owner's.
        $this->assertNotSame(200, $this->get(route('admin.announcements.index'))->getStatusCode());
        $this->assertNotSame(200, $this->get(route('admin.announcements.create'))->getStatusCode());
    }

    // =================================================================
    // 5. Team, under Settings
    // =================================================================

    /**
     * Superseded by the walkthrough settings decision: Settings → Team is the
     * account's one team — members, roles and Business access. The legacy
     * delegated-access pages are no longer offered anywhere, though their
     * routes and wording ("Team members") are untouched.
     */
    public function test_team_is_a_settings_entry_for_the_account_holder_only(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->home()->assertOk()->getContent();
        $hub = $this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertContains('team', $this->settingsHubModules($hub)['billing-team'] ?? []);
        $this->assertStringContainsString('href="' . route('customer.workspaces.team.show', $workspace->uid) . '"', $hub);
        foreach ([$this->shellText($html), html_entity_decode(strip_tags($hub))] as $text) {
            $this->assertStringNotContainsStringIgnoringCase('sub-account', $text);
            $this->assertStringNotContainsStringIgnoringCase('sub account', $text);
        }
        $this->assertStringNotContainsString(route('customer.sub_accounts.index'), $html . $hub);

        $this->assertContains('settings', $this->activeMenuKeys($this->get(route('customer.workspaces.team.show', $workspace->uid))->assertOk()->getContent()), 'Team keeps Settings active.');
        $this->get(route('customer.sub_accounts.index'))->assertOk()->assertSee('Team members');
    }

    public function test_a_team_member_never_manages_the_team(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);

        $teamMemberUser = User::create([
            'first_name' => 'Team', 'last_name' => 'Member', 'email' => 'team-member' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $teamMemberUser->parent_id = $owner->user_id;
        $teamMemberUser->save();
        $teamMember = Customer::create(['user_id' => $teamMemberUser->id]);
        $this->member(\App\Models\Workspace::query()->where('owner_user_id', $owner->user_id)->firstOrFail(), $teamMemberUser, WorkspaceMembershipRole::Staff);

        $workspace = \App\Models\Workspace::query()->where('owner_user_id', $owner->user_id)->firstOrFail();
        $business = $workspace->businesses()->firstOrFail();
        $hub = fn () => $this->settingsHubModuleKeys($this->get(route('customer.workspaces.businesses.settings.show', [$workspace->uid, $business->uid]))->assertOk()->getContent());

        $this->authenticateAs($teamMember);
        $this->assertNotContains('team', $hub());
        $this->get(route('customer.workspaces.team.show', $workspace->uid))->assertNotFound();
        $this->get(route('customer.sub_accounts.index'))->assertRedirect(route('user.home'));

        // Nor while signed in as the account holder on their behalf.
        auth()->logout();
        $this->authenticateAs($owner);
        $this->withSession(['parent_user_id' => $teamMemberUser->id, 'temp_user_id' => $owner->user_id]);
        $this->assertNotContains('team', $hub());
    }

    // =================================================================
    // 6. Footer
    // =================================================================

    public function test_the_footer_names_the_platform_brand_with_normal_copyright_wording(): void
    {
        config(['app.name' => 'Northstar Suite', 'app.footer_company_name' => null, 'app.footer_copyright_text' => null]);
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->assertSame('© ' . now()->year . ' Northstar Suite. All rights reserved.', $this->copyrightLine($this->home()->assertOk()));

        // What the platform owner configured still wins.
        Cache::forget(BrandingPresenter::CACHE_KEY);
        config(['app.footer_company_name' => 'Northstar Labs Ltd', 'app.footer_copyright_text' => 'Made with care.']);
        $this->assertSame('© ' . now()->year . ' Northstar Labs Ltd. Made with care.', $this->copyrightLine($this->home()->assertOk()));
    }

    public function test_an_agency_white_label_host_is_the_copyright_holder_never_the_platform_owner(): void
    {
        config(['app.name' => 'Northstar Suite', 'app.footer_company_name' => 'Northstar Labs Ltd', 'app.footer_copyright_text' => 'Platform wording.']);
        [$agency, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Bluebird Agency');
        $this->app->instance(AgencyBrandSource::class, new class ($workspace->uid) implements AgencyBrandSource {
            public function __construct(private readonly string $workspaceUid)
            {
            }

            public function forHost(string $host): ?AgencyBrand
            {
                return $host === 'portal.bluebird.test' ? new AgencyBrand($this->workspaceUid, 'Bluebird Marketing') : null;
            }
        });
        $this->authenticateAs($agency);

        $branded = $this->copyrightLine($this->get('http://portal.bluebird.test/dashboard')->assertOk());
        $this->assertSame('© ' . now()->year . ' Bluebird Marketing. All rights reserved.', $branded);
        $this->assertStringNotContainsString('Northstar', $branded);

        $platform = $this->copyrightLine($this->get('http://localhost/dashboard')->assertOk());
        $this->assertSame('© ' . now()->year . ' Northstar Labs Ltd. Platform wording.', $platform, 'Another host is not white-labelled.');
    }

    // =================================================================
    // 7. Avatar
    // =================================================================

    public function test_a_missing_profile_photo_is_initials_never_a_broken_image(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $customer->user->forceFill(['first_name' => 'Jazmin', 'last_name' => 'Rivera'])->save();
        $this->authenticateAs($customer);

        // No photo at all.
        $avatar = $this->avatarHtml($this->home()->assertOk()->getContent());
        $this->assertStringNotContainsString('<img', $avatar);
        $this->assertMatchesRegularExpression('/data-role="user-avatar-initials"[^>]*>\s*JR\s*</', $avatar);

        // A photo on record whose file is gone: still initials, no request.
        $customer->user->forceFill(['image' => 'app/profile/avatar-missing.jpg'])->save();
        $avatar = $this->avatarHtml($this->home()->assertOk()->getContent());
        $this->assertStringNotContainsString('<img', $avatar);
        $this->assertStringContainsString('JR', $avatar);

        // A real photo: the image, with the initials ready if it fails.
        $relative = 'app/profile/avatar-test-' . $customer->user_id . '.jpg';
        @mkdir(storage_path('app/profile'), 0777, true);
        copy(public_path('images/profile/profile.jpg'), storage_path($relative) . '.thumb.jpg');

        try {
            $customer->user->forceFill(['image' => $relative])->save();
            $avatar = $this->avatarHtml($this->home()->assertOk()->getContent());
            $this->assertStringContainsString('src="' . route('user.avatar') . '"', $avatar);
            $this->assertMatchesRegularExpression('/data-role="user-avatar-initials"[^>]*hidden/', $avatar);
            $this->assertStringContainsString('onerror=', $avatar);
        } finally {
            @unlink(storage_path($relative) . '.thumb.jpg');
        }
    }

    // -----------------------------------------------------------------

    private function publish(string $title, array $recipients, ?\DateTimeInterface $at = null): Announcements
    {
        $announcement = Announcements::create(['user_id' => $this->platformAdminId(), 'title' => $title, 'description' => '<p>' . $title . ' details.</p>', 'type' => 'email']);
        $announcement->forceFill(['created_at' => $at ?? now(), 'updated_at' => $at ?? now()])->save();

        foreach ($recipients as $user) {
            $announcement->users()->attach($user->id);
        }

        return $announcement->fresh();
    }

    private function readAt(Announcements $announcement, User $user): mixed
    {
        return DB::table('announcements_user')->where('announcements_id', $announcement->id)->where('user_id', $user->id)->value('read_at');
    }

    private function userDropdownHtml(string $html): string
    {
        return $this->between($html, 'aria-labelledby="dropdown-user"', '</li>');
    }

    private function avatarHtml(string $html): string
    {
        return $this->between($html, 'data-role="user-avatar"', '</a>');
    }

    private function copyrightLine(TestResponse $response): string
    {
        $this->assertMatchesRegularExpression('/data-role="copyright-line">([^<]*)</', $response->getContent());
        preg_match('/data-role="copyright-line">([^<]*)</', $response->getContent(), $match);

        return html_entity_decode(trim($match[1]));
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "Missing [{$start}].");
        $to = strpos($html, $end, $from);

        return substr($html, $from, ($to === false ? strlen($html) : $to) - $from);
    }
}
