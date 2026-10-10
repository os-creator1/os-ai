<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * V1 UX polish — the Website module's tabs (Website / Packages / Forms / Questionnaires / Settings), the
 * simplified overview (live site, draft, Preview / Publish / Edit), the connect-domain next step, Settings,
 * the simplified look card and the safety-at-action-time rebuild screen. The content swap is the shared
 * SectionRouter contract: `?fragment=1` answered by the SAME action, with the same gates.
 */
class WebsiteStudioNavigationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: Website} */
    private function generatedWebsite(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'));
        $this->homePage($website, ['seo_title' => 'Home', 'meta_description' => 'Welcome to the site']);

        return [$customer, $business, $workspace, $website];
    }

    private function url(object $workspace, object $business, string $tab = 'website', array $query = []): string
    {
        $base = $tab === 'website'
            ? route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid])
            : route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid, $tab]);

        return $base . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function publish(Website $website): void
    {
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
    }

    // ------------------------------------------------------------------ tabs

    public function test_the_module_has_five_tabs_each_with_its_own_direct_url(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();

        preg_match_all('#<a class="nav-link[^"]*"\s+href="([^"]+)"\s+data-website-nav data-section-key="([^"]+)"#', $html, $m, PREG_SET_ORDER);
        $this->assertSame(['website', 'packages', 'forms', 'questionnaires', 'settings'], array_column($m, 2));
        foreach (['Website', 'Packages', 'Forms', 'Questionnaires', 'Settings'] as $label) {
            $this->assertStringContainsString('>' . $label . '</a>', $html);
        }

        foreach ($m as [, $href, $key]) {
            $page = $this->get($href)->assertOk()->getContent();
            $this->assertStringContainsString('id="website-content" data-section-key="' . $key . '"', $page, "direct URL for {$key}");
            $this->assertMatchesRegularExpression('#class="nav-link\s+active\s*"\s+href="[^"]+"\s+data-website-nav data-section-key="' . $key . '"#', $page, "{$key} is the active tab");
        }
    }

    public function test_a_fragment_is_only_the_content_region_and_comes_from_the_same_action(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        foreach (['website', 'packages', 'forms', 'questionnaires', 'settings'] as $tab) {
            $full = $this->get($this->url($workspace, $business, $tab))->assertOk()->getContent();
            $fragment = $this->get($this->url($workspace, $business, $tab, ['fragment' => 1]))->assertOk()->getContent();

            $this->assertStringStartsWith('<div id="website-content" data-section-key="' . $tab . '"', trim($fragment), $tab);
            $this->assertStringNotContainsString('<html', $fragment);
            $this->assertStringNotContainsString('website-tabs', $fragment, 'the tab strip is never part of a swap');
            $this->assertStringNotContainsString('<script', $fragment, 'a section never depends on inline scripts');
            $this->assertStringContainsString(trim($fragment), $full, "the full page contains exactly the fragment ({$tab})");
        }
    }

    public function test_a_fragment_is_refused_exactly_like_the_page_for_another_customer(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();
        [$intruder] = $this->entitledTenant();
        $this->authenticateAsCustomer($intruder);

        $this->get($this->url($workspace, $business, 'settings'))->assertNotFound();
        $this->get($this->url($workspace, $business, 'settings', ['fragment' => 1]))->assertNotFound();
        $this->get($this->url($workspace, $business, 'website', ['fragment' => 1]))->assertNotFound();
    }

    public function test_a_fragment_for_a_website_that_is_not_built_yet_redirects_so_the_browser_falls_back_to_the_real_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        // Never created: the empty landing — not a tab section (no region), so the router falls back to it.
        $response = $this->get($this->url($workspace, $business, 'website', ['fragment' => 1]))->assertOk();

        $this->assertStringNotContainsString('id="website-content"', $response->getContent());
    }

    // ------------------------------------------------------------- overview

    public function test_before_publishing_the_overview_shows_the_draft_and_an_empty_live_slot_with_publish_as_the_main_action(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="live-empty"', $html);
        $this->assertStringContainsString('Not published yet', $html);
        $this->assertStringContainsString('data-testid="draft-preview"', $html);
        $this->assertStringContainsString('Draft / Preview', $html);
        $this->assertMatchesRegularExpression('#class="btn btn-primary[^"]*"[^>]*data-testid="action-publish"|data-testid="action-publish"[^>]*class="btn btn-primary#', $html);
        $this->assertStringNotContainsString('data-testid="connect-domain-card"', $html, 'nothing to connect a domain to until it is live');
        $this->assertStringNotContainsString('Live at', $html);
    }

    public function test_once_published_the_live_site_is_a_visual_that_opens_the_live_website_with_no_raw_url_banner(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $this->publish($website);
        $liveUrl = route('public.website.home', $website->fresh()->public_id);

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a class="website-preview-frame" href="' . preg_quote($liveUrl, '#') . '" target="_blank" rel="noopener" data-testid="live-preview"#', $html);
        $this->assertStringContainsString('<iframe data-site-preview src="' . $liveUrl . '"', $html);
        $this->assertStringNotContainsString('Live at', $html, 'no raw URL banner');
        $this->assertStringNotContainsString('>' . $liveUrl . '<', $html, 'the UUID address is never printed as text');
    }

    public function test_with_nothing_unpublished_the_draft_says_so_quietly_instead_of_repeating_the_live_site(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $this->publish($website);

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="draft-matches-live"', $html);
        $this->assertStringContainsString('No unpublished changes', $html);
        $this->assertStringNotContainsString('data-testid="draft-preview"', $html);
        $this->assertStringNotContainsString('data-testid="draft-changes-chip"', $html);
    }

    public function test_editing_after_publishing_shows_the_draft_as_its_own_preview_with_an_unpublished_changes_chip(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $this->publish($website);
        $this->travel(5)->seconds();
        WebsitePage::where('website_id', $website->id)->where('is_home', true)->update(['sections' => [$this->section('hero', ['heading' => 'A new headline'])], 'updated_at' => now()]);

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="draft-preview"', $html);
        $this->assertStringContainsString('data-testid="draft-changes-chip"', $html);
        $this->assertStringContainsString('Publish update', $html);
        $this->assertStringContainsString('<iframe data-site-preview src="' . route('customer.workspaces.businesses.website.preview', [$workspace->uid, $business->uid]) . '"', $html);
    }

    public function test_the_overview_has_only_preview_publish_and_edit_and_edit_goes_to_settings(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $this->publish($website);

        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="action-preview"', $html);
        $this->assertStringContainsString('data-testid="action-publish"', $html);
        $this->assertStringContainsString('data-website-go="settings"', $html, 'Edit swaps the centre like the Settings tab; without JavaScript it is still a plain link');
        $this->assertMatchesRegularExpression('#href="' . preg_quote($this->url($workspace, $business, 'settings'), '#') . '"[^>]*data-testid="action-edit"#', $html);

        foreach (['History', 'Rebuild from setup answers', 'Edit setup answers'] as $gone) {
            $this->assertStringNotContainsString('>' . $gone . '<', $html, "{$gone} is not a button on the overview any more");
        }
        $this->assertStringNotContainsString('data-testid="settings-pages"', $html);
    }

    // ---------------------------------------------------- the domain next step

    public function test_the_connect_domain_card_follows_publishing_and_disappears_once_a_domain_is_connected(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $this->publish($website);
        $domainsUrl = route('customer.workspaces.businesses.website.domains.index', [$workspace->uid, $business->uid]);

        // Published, no domain: the card is the next step.
        $html = $this->get($this->url($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="connect-domain-card"', $html);
        $this->assertStringContainsString('Connect your domain', $html);
        $this->assertStringContainsString('href="' . $domainsUrl . '"', $html);

        // Added but not live yet: the same step, honestly worded.
        $domain = $website->domains()->create(['domain' => 'www.example-booth.test', 'is_primary' => true, 'status' => WebsiteDomainStatus::PendingVerification, 'verification_token' => 't']);
        $pending = $this->get($this->url($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Finish connecting your domain', $pending);
        $this->assertStringContainsString('www.example-booth.test', $pending);

        // Connected (active primary): gone from the overview...
        $domain->update(['status' => WebsiteDomainStatus::Active, 'verified_at' => now(), 'activated_at' => now()]);
        $connected = $this->get($this->url($workspace, $business))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-testid="connect-domain-card"', $connected);
        $this->assertStringContainsString('https://www.example-booth.test/', $connected, 'the live preview now opens the real domain');

        // ...and the domain stays manageable under Settings.
        $settings = $this->get($this->url($workspace, $business, 'settings'))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . $domainsUrl . '" data-testid="settings-domain"', $settings);
        $this->assertStringContainsString('www.example-booth.test', $settings);
    }

    // -------------------------------------------------------------- settings

    public function test_settings_links_to_the_existing_canonical_screens_and_holds_the_look_card(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();
        $params = [$workspace->uid, $business->uid];

        $html = $this->get($this->url($workspace, $business, 'settings'))->assertOk()->getContent();

        foreach ([
            'settings-pages' => route('customer.workspaces.businesses.website.pages.index', $params),
            'settings-answers' => route('customer.workspaces.businesses.website.edit-setup', $params),
            'settings-template' => route('customer.workspaces.businesses.website.rebuild.form', $params),
            'settings-history' => route('customer.workspaces.businesses.website.history', $params),
            'settings-domain' => route('customer.workspaces.businesses.website.domains.index', $params),
        ] as $testid => $href) {
            $this->assertStringContainsString('href="' . $href . '" data-testid="' . $testid . '"', $html, $testid);
        }

        $this->assertStringContainsString('data-testid="studio-look"', $html);
        $this->assertStringContainsString('Template 1', $html);
    }

    public function test_the_look_card_keeps_its_controls_and_drops_the_explanations(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $html = $this->get($this->url($workspace, $business, 'settings'))->assertOk()->getContent();

        foreach (['Your website\'s look', 'Brand colour', 'Logo', 'Hero image', 'Save changes'] as $kept) {
            $this->assertStringContainsString($kept, $html, $kept);
        }

        foreach ([
            'Brand colour, logo and hero image',
            'keeps control of layout and fonts',
            'Leave blank to use the template',
            'Optional. Without one, the template shows',
            'Saved changes appear in Preview now',
            'Save look',
        ] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone);
        }
    }

    public function test_saving_the_look_from_settings_still_works_and_returns_to_settings(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $settings = $this->url($workspace, $business, 'settings');

        $this->from($settings)->post(route('customer.workspaces.businesses.website.look.update', [$workspace->uid, $business->uid]), ['brand_color' => '#224466'])
            ->assertRedirect($settings)
            ->assertSessionHas('status', 'success');

        $this->assertSame('#224466', $website->fresh()->theme['brand_color']);
    }

    // ---------------------------------------------------- template / rebuild

    public function test_the_rebuild_screen_has_no_permanent_banners_and_confirms_in_a_dialog_when_applied(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $html = $this->get(route('customer.workspaces.businesses.website.rebuild.form', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('Change your template or rebuild', $html);
        $this->assertStringContainsString('Choose a template', $html);
        $this->assertStringContainsString('data-template-card="photo_booth_modern"', $html);
        $this->assertStringNotContainsString('class="alert', $html, 'no standing alert boxes');
        $this->assertStringNotContainsString('I understand this changes my website', $html);
        $this->assertStringContainsString('id="rebuild-confirm"', $html);
        $this->assertStringContainsString('name="confirm_rebuild" id="confirm-rebuild" value=""', $html, 'unconfirmed until the dialog says so');

        // Safety is still server-side: an unconfirmed apply is refused, a confirmed one works.
        $this->post(route('customer.workspaces.businesses.website.rebuild', [$workspace->uid, $business->uid]), ['template_key' => 'photo_booth_luxury', 'mode' => 'look_only', 'confirm_rebuild' => ''])->assertSessionHasErrors('confirm_rebuild');
    }

    // ----------------------------------------------- the shared mechanism

    public function test_the_calendar_and_the_website_share_one_router_and_one_loader(): void
    {
        $router = (string) file_get_contents(resource_path('views/partials/section-router/_script.blade.php'));
        $styles = (string) file_get_contents(resource_path('views/partials/section-router/_styles.blade.php'));

        // One mechanism: fragment request, history, abort, delayed busy state, fallback to real navigation.
        foreach (["searchParams.set('fragment', '1')", 'pushState', 'popstate', 'AbortController', 'LOADING_DELAY = 150', 'window.location.assign', "setAttribute('aria-busy'"] as $needle) {
            $this->assertStringContainsString($needle, $router, $needle);
        }

        // The visible heading follows the swap (opt-in), so it never disagrees with the tab and the document title.
        $this->assertStringContainsString('headingSelector', $router);
        $this->assertStringContainsString("headingSelector: '.content-header-title'", (string) file_get_contents(resource_path('views/customer/business/website/studio/_scripts.blade.php')));

        // MotionGrove: three bars in the application's own primary colour, calm under reduced motion.
        $this->assertStringContainsString('<i></i><i></i><i></i>', $router);
        $this->assertStringContainsString('var(--color-primary', $styles);
        $this->assertStringContainsString('prefers-reduced-motion', $styles);
        $this->assertDoesNotMatchRegularExpression('/#[0-9a-fA-F]{3,8}\b/', preg_replace('/var\(--color-primary, #[0-9A-Fa-f]{6}\)/', '', $styles), 'no hard-coded colour in the loader other than the token fallback');

        foreach (['calendar/_frame.blade.php', 'website/studio/shell.blade.php'] as $consumer) {
            $source = (string) file_get_contents(resource_path('views/customer/business/' . $consumer));
            $this->assertStringContainsString("partials.section-router._script", $source, $consumer);
            $this->assertStringContainsString("partials.section-router._styles", $source, $consumer);
        }

        // The Calendar no longer carries a router of its own.
        $calendar = (string) file_get_contents(resource_path('views/customer/business/calendar/_scripts.blade.php'));
        $this->assertStringContainsString('window.SectionRouter.mount', $calendar);
        $this->assertStringNotContainsString("searchParams.set('fragment'", $calendar);
    }

    // ------------------------------------------- settings: the target layout, from canonical state

    public function test_settings_shows_the_domain_callout_and_the_two_card_layout_from_the_websites_own_state(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $params = [$workspace->uid, $business->uid];

        $html = $this->get($this->url($workspace, $business, 'settings'))->assertOk()->getContent();

        // Domain callout: no domain row at all -> "Not live yet" and "Set up domain" pointing at the canonical Domains screen.
        $this->assertStringContainsString('data-testid="settings-domain-card"', $html);
        $this->assertMatchesRegularExpression('#data-testid="settings-domain-state">\s*Not live yet\s*<#', $html);
        $this->assertStringContainsString('Connect your own domain so customers can reach your site at your address.', $html);
        $this->assertMatchesRegularExpression('#href="' . preg_quote(route('customer.workspaces.businesses.website.domains.index', $params), '#') . '" data-testid="settings-domain">Set up domain#', $html);

        // Left card: real page count, the actual template, the canonical History route.
        $this->assertMatchesRegularExpression('#settings-pages".*?website-settings-meta">\s*<span>' . $website->pages()->count() . ' (page|pages)</span>#s', $html);
        $this->assertMatchesRegularExpression('#settings-template".*?<span>Template 1</span>#s', $html);
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.website.history', $params) . '" data-testid="settings-history"', $html);
        $this->assertStringContainsString('Update what you told us about your business', $html);

        // Right card: the look, with the "nothing yet" upload states and the alt-text fields; no hard-coded colour.
        $this->assertStringContainsString('No logo yet', $html);
        $this->assertStringContainsString('No hero image yet', $html);
        $this->assertStringContainsString('Upload an image file', $html);
        $this->assertStringContainsString('The large photo at the top of your home page', $html);
        $this->assertStringContainsString('placeholder="Alt text (e.g. Your business logo)"', $html);
        $this->assertStringContainsString('placeholder="Alt text (describe the photo)"', $html);
        $this->assertStringContainsString('id="look-brand-color" name="brand_color" value=""', $html);
        $this->assertStringNotContainsString('#9a35cd', $html);
    }

    public function test_the_domain_callout_follows_the_real_domain_state(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();

        $domain = $website->domains()->create(['domain' => 'www.example-booth.test', 'is_primary' => true, 'status' => WebsiteDomainStatus::PendingVerification, 'verification_token' => 't']);
        $pending = $this->get($this->url($workspace, $business, 'settings'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#settings-domain-state">\s*Not live yet\s*<#', $pending);
        $this->assertStringContainsString('Finish setup', $pending);

        $domain->update(['status' => WebsiteDomainStatus::Active, 'verified_at' => now(), 'activated_at' => now()]);
        $live = $this->get($this->url($workspace, $business, 'settings'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#settings-domain-state">\s*Live\s*<#', $live);
        $this->assertStringContainsString('Your website is reachable at www.example-booth.test.', $live);
        $this->assertStringContainsString('Manage domain', $live);
        $this->assertStringNotContainsString('Connect your own domain', $live);
    }

    public function test_the_look_card_shows_the_saved_brand_colour_and_persists_a_change(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $settings = $this->url($workspace, $business, 'settings');
        $website->forceFill(['theme' => array_merge($website->theme ?? [], ['brand_color' => '#93a5cd'])])->save();

        $html = $this->get($settings)->assertOk()->getContent();
        $this->assertStringContainsString('id="look-brand-picker" value="#93a5cd"', $html);
        $this->assertStringContainsString('id="look-brand-color" name="brand_color" value="#93a5cd"', $html);

        $this->from($settings)->post(route('customer.workspaces.businesses.website.look.update', [$workspace->uid, $business->uid]), ['brand_color' => '#224466'])
            ->assertRedirect($settings);

        $this->assertStringContainsString('id="look-brand-color" name="brand_color" value="#224466"', $this->get($settings)->getContent());
        $this->assertSame('#224466', $website->fresh()->theme['brand_color']);
    }
}
