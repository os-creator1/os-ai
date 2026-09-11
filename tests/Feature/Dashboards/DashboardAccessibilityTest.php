<?php

namespace Tests\Feature\Dashboards;

use App\Enums\Dashboard\AttentionSeverity;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Dashboards\Concerns\CreatesDashboardFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 4 §14, §15, §18 #31–#33, #37 — landmarks and
 * headings, focusable actions, words instead of colour, no fixed widths, and
 * no page stylesheet, in every dashboard branch.
 */
class DashboardAccessibilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesDashboardFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        config(['opportunity.enabled' => true]);
    }

    public function test_every_branch_has_one_main_one_h1_labelled_sections_and_no_skipped_heading_level(): void
    {
        foreach ($this->everyBranch() as $branch => $html) {
            $this->assertSame(1, substr_count($html, '<main'), "{$branch}: one <main>.");
            $this->assertSame(1, preg_match_all('/<h1[\s>]/', $html), "{$branch}: one <h1>.");

            $main = $this->mainHtml($html);

            preg_match_all('/<h([1-6])[\s>]/', $main, $levels);
            $levels = array_map('intval', $levels[1]);
            $this->assertSame(1, $levels[0] ?? null, "{$branch}: the page's first heading is its <h1>.");
            foreach (array_slice($levels, 1) as $i => $level) {
                $this->assertLessThanOrEqual($levels[$i] + 1, $level, "{$branch}: heading levels never skip (" . implode(',', $levels) . ').');
            }

            preg_match_all('/<section\b[^>]*>/', $main, $sections);
            $this->assertNotEmpty($sections[0], "{$branch}: the page is made of sections.");
            foreach ($sections[0] as $section) {
                $this->assertMatchesRegularExpression('/aria-labelledby="([^"]+)"/', $section, "{$branch}: {$section}");
                preg_match('/aria-labelledby="([^"]+)"/', $section, $id);
                $this->assertMatchesRegularExpression('/<h[2-6][^>]*id="' . preg_quote($id[1], '/') . '"/', $main, "{$branch}: the label {$id[1]} is a real heading.");
            }
        }
    }

    public function test_every_action_is_a_real_link_or_button_and_every_status_is_a_word(): void
    {
        foreach ($this->everyBranch() as $branch => $html) {
            $main = $this->mainHtml($html);

            $this->assertDoesNotMatchRegularExpression('/<(div|span|li|td)\b[^>]*\bonclick=/i', $main, "{$branch}: no clickable non-control.");
            $this->assertDoesNotMatchRegularExpression('/tabindex="-1"/', $main, "{$branch}: nothing is taken out of the focus order.");

            preg_match_all('/<a\b[^>]*>/', $main, $links);
            foreach ($links[0] as $link) {
                $this->assertMatchesRegularExpression('/\bhref="(?!#")[^"]+"/', $link, "{$branch}: every link goes somewhere.");
            }

            preg_match_all('/<button\b[^>]*>/', $main, $buttons);
            foreach ($buttons[0] as $button) {
                $this->assertMatchesRegularExpression('/type="(submit|button)"/', $button, "{$branch}: {$button}");
            }

            preg_match_all('/data-severity="([a-z]+)"(.*?)<\/li>/s', $main, $items, PREG_SET_ORDER);
            foreach ($items as [, $severity, $body]) {
                $this->assertStringContainsString(AttentionSeverity::from($severity)->word(), strip_tags($body), "{$branch}: severity {$severity} is spelled out.");
            }
        }
    }

    public function test_no_fixed_width_forces_a_horizontal_scroll_and_bands_stack_on_small_screens(): void
    {
        foreach ($this->everyBranch() as $branch => $html) {
            $main = $this->mainHtml($html);

            $this->assertDoesNotMatchRegularExpression('/style="[^"]*\b(width|min-width)\s*:/i', $main, "{$branch}: no inline fixed width.");
            $this->assertDoesNotMatchRegularExpression('/\bw-(25|50|75)\b|\bcol-(?!12\b)\d+\b(?![^"]*\bcol-12\b)/', $this->nonDefinitionListHtml($main), "{$branch}: every grid column is full width at 375 px.");
        }
    }

    public function test_the_dashboard_loads_no_page_stylesheet_and_the_retired_asset_is_gone(): void
    {
        foreach ($this->everyBranch() as $branch => $html) {
            $this->assertStringNotContainsString('dashboard-ecommerce', $html, $branch);
            $this->assertStringNotContainsString('css/base/pages/', $html, "{$branch}: no page stylesheet.");
        }

        $source = file_get_contents(resource_path('views/customer/dashboard.blade.php'));
        $this->assertStringNotContainsString("@section('page-style')", $source);

        $manifest = json_decode(file_get_contents(public_path('mix-manifest.json')), true);
        $this->assertArrayNotHasKey('/css/base/pages/dashboard-ecommerce.css', $manifest);
        $this->assertFileDoesNotExist(public_path('css/base/pages/dashboard-ecommerce.css'));
        $this->assertFileDoesNotExist(resource_path('scss/base/pages/dashboard-ecommerce.scss'), 'Without its source the next build cannot re-publish it.');
    }

    /**
     * Rendered pages for every branch: Business Home (all five bands), a
     * degraded-free Agency Account Home, the chooser and the zero state.
     *
     * @return array<string, string>
     */
    private function everyBranch(): array
    {
        $pages = [];

        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Structure Venue', 'Structure Account');
        $this->wallet($business, ['billing_status' => 'suspended', 'available_balance_micro' => 1000000, 'auto_recharge_threshold_micro' => 5000000]);
        $this->website($business, 'draft');
        $this->googleConnection($business, GoogleConnectionState::Revoked);
        $this->recommendation($business, ['title' => 'Structure recommendation']);
        $this->sent($business, 2, '2026-09-01');
        $this->authenticateAs($owner);
        $pages['business'] = $this->home()->assertOk()->getContent();

        $this->addBusiness($owner, $workspace, 'Second Structure Venue');
        session()->forget(array_keys(session()->all()));
        $this->authenticateAs($owner);
        $pages['chooser'] = $this->home()->assertOk()->getContent();

        [$agency, $client, $agencyWorkspace] = $this->tenant(WorkspacePlanTier::Agency, 'Agency Client One', 'Structure Agency');
        $this->addBusiness($agency, $agencyWorkspace, 'Agency Client Two');
        $this->wallet($client, ['debt_balance_micro' => 10]);
        $this->authenticateAs($agency);
        $pages['agency'] = $this->home()->assertOk()->getContent();

        $nobody = $this->createCustomer();
        $this->authenticateAs($nobody);
        $pages['zero'] = $this->home()->assertOk()->getContent();

        foreach (['business' => 'business', 'chooser' => 'chooser', 'agency' => 'agency', 'zero' => 'zero'] as $branch => $kind) {
            $this->assertStringContainsString('data-kind="' . $kind . '"', $pages[$branch], "Precondition: the {$branch} branch rendered.");
        }

        return $pages;
    }

    /** Definition lists legitimately use col-6 label/value pairs; everything else must be single-column at 375 px. */
    private function nonDefinitionListHtml(string $main): string
    {
        return preg_replace('#<dl\b.*?</dl>#s', '', $main) ?? $main;
    }
}
