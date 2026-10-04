<?php

namespace Tests\Feature\GoogleAds\Http;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Http\Concerns\CreatesAdsHttpFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 — the Budget page (google_ads_module only): the
 * Business monthly target vs spend vs projection, the pacing badge in calm
 * wording, target vs current cost per conversion, and the per-campaign daily
 * budget table kept distinct from the monthly target.
 *
 * Spec example: target 250, spent 112 after 14 of 31 days, projected 248.
 */
class AdsBudgetPageTest extends TestCase
{
    use CreatesAdsHttpFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareAdsHttp();
        $this->pinAdsClock('2026-10-14 12:00:00');
    }

    protected function tearDown(): void
    {
        $this->unpinAdsClock();
        parent::tearDown();
    }

    private function budgetHtml(int $targetMicros = 250_000_000, ?int $cplTarget = null): string
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, [
            'monthly_budget_target_micros' => $targetMicros === 0 ? null : $targetMicros,
            'target_cpl_micros' => $cplTarget,
            'data_through_date' => '2026-10-13',
        ]);
        $this->seedCampaign($account, '111', 'Wedding Photo Booth', ['budget_amount_micros' => 30_000_000, 'budget_shared' => false]);
        $this->seedCampaign($account, '222', 'Corporate <b>Events</b>', ['budget_amount_micros' => 12_000_000, 'budget_shared' => true]);
        $this->seedCampaignDays($account, '111', '2026-10-01', '2026-10-14', 8_000_000, 10, '2');
        $this->asAdsUser($customer);

        return $this->get($this->adsUrl($workspace, $business, 'budget'))->assertOk()->getContent();
    }

    private function text(string $html, string $role): string
    {
        $this->assertSame(1, preg_match('/data-role="' . preg_quote($role, '/') . '"[^>]*>(.*?)<\/(?:p|span|a)>/s', $html, $m), "[{$role}] not found");

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[1])));
    }

    public function test_the_spec_example_shows_target_spent_projected_and_on_pace(): void
    {
        $html = $this->budgetHtml();

        $this->assertSame('USD 250.00', $this->text($html, 'budget-target'));
        $this->assertSame('USD 112.00', $this->text($html, 'budget-spent'));
        $this->assertSame('USD 248.00', $this->text($html, 'budget-projected'));
        $this->assertSame('On pace', $this->text($html, 'pacing-status'));
        $this->assertStringContainsString('data-status="on_pace"', $html);
        $this->assertStringNotContainsString('data-role="budget-projected-note"', $html, 'enough days: no low-confidence note');
        $this->assertStringContainsString('45% of the month elapsed', $html);
    }

    public function test_ahead_of_pace_is_amber_never_red(): void
    {
        $html = $this->budgetHtml(100_000_000);

        $this->assertSame('Ahead of pace', $this->text($html, 'pacing-status'));
        $region = substr($html, (int) strpos($html, 'data-section="pacing"'), 4000);
        $this->assertStringContainsString('bg-light-warning', $region);
        $this->assertStringNotContainsString('bg-light-danger', $html);
        $this->assertStringNotContainsString('alert-danger', substr($html, (int) strpos($html, 'data-role="ads-title"')));
    }

    public function test_behind_pace_is_neutral_wording(): void
    {
        $html = $this->budgetHtml(1_000_000_000);

        $this->assertSame('Behind pace', $this->text($html, 'pacing-status'));
        $this->assertStringContainsString('below your target', $html);
    }

    public function test_no_target_set_and_the_link_to_settings(): void
    {
        $html = $this->budgetHtml(0);

        $this->assertSame('No target set', $this->text($html, 'pacing-status'));
        $this->assertSame('—', $this->text($html, 'budget-target'));
        $this->assertSame('Set a monthly target', $this->text($html, 'edit-targets'));
        $this->assertStringContainsString('/ads/settings', $html);
    }

    public function test_not_enough_data_yet(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $account = $this->selectedAdsAccount($business, ['monthly_budget_target_micros' => 250_000_000]);
        $this->seedCampaign($account, '111', 'Wedding Photo Booth');
        $this->seedCampaignDays($account, '111', '2026-10-12', '2026-10-14', 8_000_000, 10, '2');
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business, 'budget'))->assertOk()->getContent();

        $this->assertSame('Not enough data yet', $this->text($html, 'pacing-status'));
        $this->assertStringContainsString('data-role="budget-projected-note"', $html, 'few days: early-estimate note');
    }

    public function test_target_cpl_versus_current_cpl(): void
    {
        // 14 days x $8 / 28 conversions = $4.00 current; target $5 => better than target.
        $html = $this->budgetHtml(250_000_000, 5_000_000);

        $this->assertSame('USD 5.00', $this->text($html, 'budget-target-cpl'));
        $this->assertSame('USD 4.00', $this->text($html, 'budget-current-cpl'));
        $this->assertSame('Better than your target', $this->text($html, 'cpl-status'));

        $worse = $this->budgetHtml(250_000_000, 3_000_000);
        $this->assertSame('Above your target', $this->text($worse, 'cpl-status'));
    }

    public function test_the_campaign_daily_budget_table_shows_shared_budgets_and_escapes_names(): void
    {
        $html = $this->budgetHtml();

        $this->assertStringContainsString('Campaign daily budgets', $html);
        $this->assertStringContainsString('USD 30.00', $html);
        $this->assertStringContainsString('USD 12.00', $html);
        $this->assertStringContainsString('Shared', $html);
        $this->assertStringContainsString('Corporate &lt;b&gt;Events&lt;/b&gt;', $html);
        $this->assertStringNotContainsString('Corporate <b>Events</b>', $html);
        $this->assertStringContainsString('Two different budgets', $html);
        $this->assertStringContainsString('Daily budgets', $html);
        $this->assertStringContainsString('Your monthly target', $html);
    }

    public function test_the_budget_page_never_mentions_ai_and_makes_no_provider_call(): void
    {
        $html = $this->budgetHtml();
        $start = (int) strpos($html, 'data-role="ads-title"');
        $end = strpos($html, '<footer', $start);
        $content = substr($html, $start, ($end === false ? strlen($html) : $end) - $start);

        $this->assertDoesNotMatchRegularExpression('/\bAI\b/', strip_tags($content));
        $this->assertSame(0, $this->fakeAds->callCount());
    }

    public function test_the_budget_page_shows_the_standard_empty_state_without_an_account(): void
    {
        [$customer, $business, $workspace] = $this->adsHttpTenant();
        $this->asAdsUser($customer);

        $html = $this->get($this->adsUrl($workspace, $business, 'budget'))->assertOk()->getContent();

        $this->assertStringContainsString('Connect Google Ads to see where your ad budget is generating results.', $html);
        $this->assertStringNotContainsString('data-section="pacing"', $html);
    }

    public function test_the_freshness_line_appears_on_the_budget_page(): void
    {
        $html = $this->budgetHtml();

        $this->assertStringContainsString('data-role="ads-freshness"', $html);
        $this->assertStringContainsString('Data through Oct 13, 2026', $html);
    }
}
