<?php

namespace Tests\Feature\Website;

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
 * Website builder redesign (UI only): the one scoped `.wb` stylesheet, the shared header + tab strip, the unpublished-changes
 * banner, the live/draft cards, the domain + health row, the Settings two-column layout, the Packages / Forms / Questionnaires
 * screens and the change-template flow (choose, confirm dialog, rebuilding panel). Behaviour hooks (routes, form names,
 * data-testid / data-* attributes) are asserted here so a later restyle cannot silently drop them.
 */
class WebsiteBuilderRedesignTest extends TestCase
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

    private function tabUrl(object $workspace, object $business, string $tab = 'website'): string
    {
        return $tab === 'website'
            ? route('customer.workspaces.businesses.website.show', [$workspace->uid, $business->uid])
            : route('customer.workspaces.businesses.website.studio.show', [$workspace->uid, $business->uid, $tab]);
    }

    private function publish(Website $website): void
    {
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
    }

    public function test_every_tab_renders_inside_the_one_scoped_builder_wrapper_with_the_shared_header_and_tabs(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        foreach (['website', 'packages', 'forms', 'questionnaires', 'settings'] as $tab) {
            $html = $this->get($this->tabUrl($workspace, $business, $tab))->assertOk()->getContent();

            $this->assertStringContainsString('<div class="wb">', $html, $tab);
            $this->assertSame(1, substr_count($html, '.wb {'), "{$tab}: the stylesheet is emitted exactly once");
            $this->assertStringContainsString('class="wb-title">Website</h1>', $html, $tab);
            $this->assertStringContainsString('data-testid="action-edit"', $html, $tab);
            $this->assertStringContainsString('data-website-go="settings"', $html, $tab);
            // The tab strip keeps the router hooks.
            $this->assertSame(5, substr_count($html, 'data-website-nav data-section-key='), $tab);
            $this->assertStringContainsString('aria-current="page"', $html, $tab);
        }
    }

    public function test_change_template_button_is_in_the_header_and_only_visible_on_the_website_tab(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();
        $rebuild = route('customer.workspaces.businesses.website.rebuild.form', [$workspace->uid, $business->uid]);

        $website = $this->get($this->tabUrl($workspace, $business))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#href="' . preg_quote($rebuild, '#') . '" data-testid="action-change-template" data-website-only="website"\s*>#', $website);

        $packages = $this->get($this->tabUrl($workspace, $business, 'packages'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#data-website-only="website"\s+hidden\s*>#', $packages);
    }

    public function test_unpublished_changes_show_the_amber_banner_with_preview_draft_and_publish_update(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $this->publish($website);
        $this->travel(5)->seconds();
        WebsitePage::where('website_id', $website->id)->where('is_home', true)->update(['sections' => [$this->section('hero', ['heading' => 'A new headline'])], 'updated_at' => now()]);

        $html = $this->get($this->tabUrl($workspace, $business))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#class="website-actions\s+is-draft\s*"#', $html);
        $this->assertStringContainsString('You have unpublished changes', $html);
        $this->assertStringContainsString('Compare below, then publish when you', $html);
        $this->assertMatchesRegularExpression('#data-testid="action-preview"[^>]*>.*?Preview draft#s', $html, 'the Preview action reads "Preview draft"');
        $this->assertStringContainsString('data-testid="action-preview"', $html);
        $this->assertStringContainsString('data-testid="action-publish"', $html);
        $this->assertStringContainsString('Publish update', $html);
        $this->assertStringContainsString('Live now', $html);
        $this->assertStringContainsString('What visitors see', $html);
        $this->assertSame(1, substr_count($html, 'data-testid="action-publish"'), 'one publish action, never duplicated');
    }

    public function test_health_row_shows_a_proportional_segmented_bar_and_keeps_the_details_fold(): void
    {
        [, $business, $workspace, $website] = $this->generatedWebsite();
        $this->publish($website);

        $html = $this->get($this->tabUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-testid="website-health-fold"', $html);
        $this->assertStringContainsString('class="wb-health-bar"', $html);
        $this->assertMatchesRegularExpression('#<span class="ok" style="flex: \d+"></span>#', $html);
        $this->assertStringContainsString('See details', $html);
        $this->assertStringContainsString('data-testid="website-health"', $html, 'the full checks list stays inside the fold');
        $this->assertStringContainsString('data-testid="connect-domain-card"', $html);
    }

    public function test_settings_keeps_the_two_column_layout_the_grouped_look_card_and_the_save_form(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $html = $this->get($this->tabUrl($workspace, $business, 'settings'))->assertOk()->getContent();

        $this->assertStringContainsString('website-settings-grid', $html);
        $this->assertStringContainsString('data-testid="settings-domain-card"', $html);
        foreach (['colors', 'images', 'legal'] as $group) {
            $this->assertStringContainsString('data-look-group="' . $group . '"', $html, $group);
        }
        foreach (['Colors', 'Images', 'Legal links'] as $eyebrow) {
            $this->assertStringContainsString('class="wb-eyebrow">' . $eyebrow . '<', $html, $eyebrow);
        }
        // Saving is still the canonical endpoint with every field name unchanged.
        $this->assertStringContainsString('action="' . route('customer.workspaces.businesses.website.look.update', [$workspace->uid, $business->uid]) . '"', $html);
        foreach (['brand_color', 'secondary_brand_color', 'dark_bg_color', 'logo', 'hero', 'logo_alt', 'hero_alt', 'privacy_notice_url', 'terms_url'] as $field) {
            $this->assertStringContainsString('name="' . $field . '"', $html, $field);
        }
        $this->assertStringContainsString('data-testid="look-save"', $html);
    }

    public function test_packages_use_the_reference_card_with_an_add_another_tile(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $empty = $this->get($this->tabUrl($workspace, $business, 'packages'))->assertOk()->getContent();
        $this->assertStringContainsString('No packages yet', $empty);
        $this->assertStringContainsString('data-testid="add-package"', $empty);
        $this->assertStringNotContainsString('<style>' . "\n" . '    .mg-pkg-head', $empty, 'package styles live in the shared builder stylesheet, not inline');
    }

    public function test_forms_and_questionnaires_use_the_designed_empty_states(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $forms = $this->get($this->tabUrl($workspace, $business, 'forms'))->assertOk()->getContent();
        $this->assertStringContainsString('data-testid="forms-empty"', $forms);
        $this->assertStringContainsString('No quote form yet', $forms);
        $this->assertStringContainsString('Answers land in Contacts.', $forms);
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.website.forms.index', [$workspace->uid, $business->uid]) . '"', $forms);

        $questionnaires = $this->get($this->tabUrl($workspace, $business, 'questionnaires'))->assertOk()->getContent();
        if (str_contains($questionnaires, 'data-testid="questionnaires-empty"')) {
            $this->assertStringContainsString('No questionnaire answers yet', $questionnaires);
            $this->assertStringContainsString('You can send it automatically with an automation.', $questionnaires);
        } else {
            $this->assertStringContainsString('Edit answers', $questionnaires, 'a completed setup response keeps its Edit answers action');
        }
    }

    public function test_change_template_page_keeps_every_form_hook_and_gains_the_stepped_layout_and_summary_bar(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $html = $this->get(route('customer.workspaces.businesses.website.rebuild.form', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('<div class="wb">', $html);
        $this->assertStringContainsString('class="wb-title">Change template</h1>', $html);
        $this->assertStringContainsString('Change your template or rebuild', $html);
        $this->assertStringContainsString('Choose a template', $html);
        $this->assertStringContainsString('What should change?', $html);
        $this->assertStringContainsString('class="wb-step-n"', $html);

        // Form contract unchanged.
        $this->assertStringContainsString('action="' . route('customer.workspaces.businesses.website.rebuild', [$workspace->uid, $business->uid]) . '"', $html);
        foreach (['idempotency_key', 'confirm_rebuild', 'template_key', 'mode'] as $name) {
            $this->assertStringContainsString('name="' . $name . '"', $html, $name);
        }
        $this->assertStringContainsString('id="rebuild-mode-look" value="look_only" checked', $html);
        $this->assertStringContainsString('id="rebuild-mode-full" value="full"', $html);
        $this->assertStringContainsString('data-template-card="photo_booth_modern"', $html);
        $this->assertStringContainsString('data-testid="template-current"', $html);
        $this->assertStringContainsString('id="rebuild-apply"', $html);

        // Sticky bar + restyled confirm dialog + the rebuilding panel template.
        $this->assertStringContainsString('class="wb-bar"', $html);
        $this->assertStringContainsString('id="rebuild-confirm"', $html);
        $this->assertStringContainsString('id="rebuild-confirm-points"', $html);
        $this->assertStringContainsString('data-testid="layout-change-warning"', $html);
        $this->assertStringContainsString('Rebuild pages', $html);
        $this->assertStringContainsString('Edits you made to draft pages will be replaced', $html);
        $this->assertStringContainsString('id="generation-progress-template"', $html);
        $this->assertStringContainsString('class="wb-progress-track wb-only"', $html);
        $this->assertStringContainsString('Keep this page open until it finishes.', $html);
        $this->assertStringContainsString('data-testid="generation-progress-text"', $html);
    }

    public function test_the_server_still_refuses_an_unconfirmed_rebuild_after_the_redesign(): void
    {
        [, $business, $workspace] = $this->generatedWebsite();

        $this->post(route('customer.workspaces.businesses.website.rebuild', [$workspace->uid, $business->uid]), [
            'template_key' => 'photo_booth_luxury',
            'mode' => 'look_only',
            'confirm_rebuild' => '',
        ])->assertSessionHasErrors('confirm_rebuild');
    }
}
