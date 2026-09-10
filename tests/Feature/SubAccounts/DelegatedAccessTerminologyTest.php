<?php

namespace Tests\Feature\SubAccounts;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Redesign Slice 1 (Correction 3, contract §4) — the
 * legacy delegated-access ("Sub-Accounts") feature's customer-visible noun
 * is now "Team member"/"Team members", never "Sub Account(s)"/
 * "Sub-Account(s)". Internal identifiers (route names `customer.
 * sub_accounts.*`, `SubAccountController`, the `sub_accounts` locale KEY
 * names, `users.parent_id`) are unchanged by design and are never asserted
 * against here — only rendered customer copy.
 *
 * NOTE on scope: the invitation EMAIL's actual subject/body come from the
 * `email_templates` database row seeded by
 * database/migrations/2025_06_02_165201_create_sub_account_invitation_email_template.php
 * (via App\Mail\SubAccountInvitation::content() -> Tool::renderTemplate()),
 * not from resources/lang/en/locale.php's `sub_accounts.invitation.*` keys
 * — those two locale keys are corrected per the contract but have no
 * current consumer (confirmed: zero references anywhere in app/ or
 * resources/views/). The seeded email subject/content still contain the
 * literal "Sub-Account" wording. Fixing that requires either a migration
 * edit or a data change to already-migrated installs, both outside this
 * slice's allowlist (`database/migrations/**` is on the stop-list) — a
 * discovered, reported, out-of-scope residual, not silently left
 * untested: this file does not assert on the invitation email's content.
 */
class DelegatedAccessTerminologyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const FORBIDDEN_PATTERN = '/sub[\s-]?account/i';

    private function createTeamMember(Customer $owner): User
    {
        $subAccountUser = User::create([
            'first_name' => 'Team',
            'last_name' => 'Member',
            'email' => 'team-member-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        // parent_id is not mass-assignable on the User model — set directly.
        $subAccountUser->parent_id = $owner->user_id;
        $subAccountUser->save();

        Customer::create(['user_id' => $subAccountUser->id]);

        return $subAccountUser->fresh();
    }

    public function test_navbar_dropdown_says_team_members_not_sub_accounts(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $home = $this->home()->assertOk();
        $html = $home->getContent();

        $home->assertSee('Team members', false);
        $home->assertSee(route('customer.sub_accounts.index'), false);
        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_PATTERN, $this->navbarDropdownText($html));
    }

    public function test_sub_accounts_index_page_uses_team_member_wording(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.sub_accounts.index'))->assertOk();
        $html = $response->getContent();

        $response->assertSee('Team members', false);
        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_PATTERN, $this->pageBodyText($html));
    }

    public function test_sub_accounts_create_page_uses_team_member_wording(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.sub_accounts.create'))->assertOk();
        $html = $response->getContent();

        $response->assertSee('Add team member', false);
        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_PATTERN, $this->pageBodyText($html));
    }

    public function test_sub_accounts_show_page_uses_team_member_wording(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $teamMember = $this->createTeamMember($customer);

        $response = $this->get(route('customer.sub_accounts.show', $teamMember->uid))->assertOk();
        $html = $response->getContent();

        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_PATTERN, $this->pageBodyText($html));
    }

    public function test_a_team_member_who_is_not_the_owner_is_restricted_from_the_management_routes(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);
        $teamMemberUser = $this->createTeamMember($owner);
        $teamMemberUser->email_verified_at = now();
        $teamMemberUser->save();
        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($teamMemberUser);

        $this->get(route('customer.sub_accounts.index'))->assertRedirect(route('user.home'));
    }

    public function test_dashboard_login_as_parent_banner_says_team_member_not_sub_account(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);
        $teamMemberUser = $this->createTeamMember($owner);
        $teamMemberUser->email_verified_at = now();
        $teamMemberUser->save();
        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($teamMemberUser);

        $response = $this->get(route('user.home'))->assertOk();
        $html = $response->getContent();

        $response->assertSee('You are currently logged in as a team member.', false);
        $this->assertDoesNotMatchRegularExpression(self::FORBIDDEN_PATTERN, $this->pageBodyText($html));
    }

    /**
     * Visible text of the navbar's user dropdown — attributes (the
     * `sub-accounts` href, a permitted route segment) and tags stripped.
     */
    private function navbarDropdownText(string $html): string
    {
        $start = strpos($html, 'dropdown-user-link');
        $end = strpos($html, '</ul>', $start === false ? 0 : $start);

        $this->assertNotFalse($start, 'The navbar user dropdown must be present.');

        $region = substr($html, $start, ($end ?: strlen($html)) - $start);
        $withoutAttributes = preg_replace('/\s[a-zA-Z-]+="[^"]*"/', '', $region) ?? '';

        return html_entity_decode(strip_tags($withoutAttributes));
    }

    /**
     * Visible page body text (everything the shell/breadcrumb region does
     * not own) — not the breadcrumb-exclusive scoping of the shell parsers
     * in CreatesCustomerContextFixtures. <script>/<style> contents and
     * every HTML attribute are stripped first: the `sub-accounts` URL
     * prefix (route('customer.sub_accounts.search') etc., embedded in
     * inline JS and in href/action attributes) matches the same
     * "sub-account" pattern this test forbids in customer copy, but is a
     * permitted technical route segment, not rendered text.
     */
    private function pageBodyText(string $html): string
    {
        $start = strpos($html, '<div class="app-content');
        $this->assertNotFalse($start, 'The app content region must be present.');

        $region = substr($html, $start);
        $withoutScripts = preg_replace('#<script\b[^>]*>.*?</script>#si', '', $region) ?? '';
        $withoutStyles = preg_replace('#<style\b[^>]*>.*?</style>#si', '', $withoutScripts) ?? '';
        $withoutAttributes = preg_replace('/\s[a-zA-Z-]+="[^"]*"/', '', $withoutStyles) ?? '';

        return html_entity_decode(strip_tags($withoutAttributes));
    }
}
