<?php

namespace Tests\Feature\Theme;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 2 — the shared authenticated shell through the
 * allowlisted layouts (contract §9.2; brief §6, §10): a skip link and one
 * main landmark, a document title that names the page and the current
 * Business, the Slice 1B context, switcher and View-as banner left intact,
 * and a user menu that signs out in plain words. Rendered-DOM assertions.
 */
class CustomerShellLayoutTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    public function test_the_shell_exposes_a_skip_link_and_exactly_one_main_landmark(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('href="#main-content"', $html);
        $this->assertStringContainsString('Skip to main content', $html);
        $this->assertSame(1, preg_match_all('/<main\b[^>]*id="main-content"/', $html));
        $this->assertSame(1, preg_match_all('/<main\b/', $html));
    }

    public function test_the_document_title_names_the_page_and_the_current_business(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Harbor Lane Studios', 'Harbor Lane');
        $this->authenticateAs($owner);

        $html = $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        preg_match('/<title>(.*?)<\/title>/s', $html, $title);
        $this->assertNotEmpty($title);
        $this->assertStringContainsString('Harbor Lane Studios', $title[1]);
        $this->assertStringNotContainsString('locale.', $title[1]);
        $this->assertStringNotContainsString('Workspace', $title[1]);
    }

    public function test_the_agency_account_frame_title_names_the_agency(): void
    {
        [$owner, , $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($owner, $workspace, 'Client Two');
        $this->authenticateAs($owner);

        $html = $this->get(route('customer.workspaces.show', $workspace->uid))->assertOk()->getContent();

        preg_match('/<title>(.*?)<\/title>/s', $html, $title);
        $this->assertStringContainsString('Northwind Agency', $title[1]);
    }

    public function test_the_user_menu_offers_profile_and_sign_out_in_plain_words(): void
    {
        [$owner] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($owner);

        $html = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('Sign out', $html);
        $this->assertStringNotContainsString('>Logout<', $html);
        $this->assertStringContainsString('id="logout-form"', $html);
        $this->assertStringContainsString('action="' . route('logout') . '"', $html);
        $this->assertStringContainsString(route('user.account'), $html);
    }

    public function test_slice_1b_context_switcher_and_view_as_banner_are_preserved(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency, 'Client One', 'Northwind Agency');
        $this->addBusiness($owner, $workspace, 'Client Two');
        $this->authenticateAs($owner);

        $home = $this->home()->assertOk()->getContent();
        $this->assertStringContainsString('data-role="sidebar-context"', $home);
        $this->assertStringContainsString('id="customer-context-switcher-toggle"', $home);
        $this->assertStringContainsString('aria-label="Choose a client account"', $home, 'Two reachable Businesses and no choice yet: the Account frame asks.');

        $this->switchTo($workspace, $business)->assertRedirect(route('user.home'));
        $selected = $this->home()->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/aria-label="Current client account: Client One\. Switch client account"/', $selected);

        $this->startViewAs($workspace, $business)->assertRedirect(route('user.home'));
        $viewing = $this->home()->assertOk()->getContent();

        $this->assertStringContainsString('data-role="view-as-banner"', $viewing);
        $this->assertStringContainsString('role="status"', $viewing);
        $this->assertStringContainsString('Exit client view', $viewing);
        $this->assertStringContainsString('aria-label="Current client account"', $viewing);
    }

    public function test_the_guest_layout_and_the_shell_share_one_main_landmark_convention(): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $guest = $this->get(route('login'))->assertOk()->getContent();

        $this->assertSame(1, preg_match_all('/<main\b[^>]*id="main-content"/', $guest));
        $this->assertStringNotContainsString('href="#main-content"', $guest, 'A single-form guest page needs no skip link.');
    }
}
