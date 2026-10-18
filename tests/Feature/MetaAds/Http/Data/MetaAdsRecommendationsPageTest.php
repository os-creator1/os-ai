<?php

namespace Tests\Feature\MetaAds\Http\Data;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\MetaAds\MetaAdsLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\MetaAds\Http\Data\Concerns\CreatesMetaAdsDataFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 section 12 — the Recommendations page: a
 * read-only presentation of deterministic facts. Calm tones (never red), a
 * positive card without an action, no dismiss / snooze / apply, an honest
 * empty state and the "choose a result type" hint. Cached data only.
 */
class MetaAdsRecommendationsPageTest extends TestCase
{
    use CreatesMetaAdsDataFixtures;
    use RefreshDatabase;

    private const XSS = '<script>alert(1)</script>';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareMetaHttp();
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    /** @return array{0: \App\Models\Workspace, 1: \App\Models\Business, 2: \App\Models\MetaAdsAccount, 3: array<string, \App\Models\MetaAdsCampaign>} */
    private function ready(bool $seed = true, array $accountOverrides = [], WorkspacePlanTier $tier = WorkspacePlanTier::Growth, array $permissions = [self::VIEW, self::MANAGE]): array
    {
        [$customer, $business, $workspace, $account] = $this->dataTenant($tier, array_merge(['target_cost_per_result_micros' => 10_000_000], $accountOverrides));
        $rows = $seed ? $this->seedFacts($account) : [];
        $this->asMetaUser($customer, $permissions);

        return [$workspace, $business, $account, $rows];
    }

    /** @return array<string, \App\Models\MetaAdsCampaign> */
    private function seedFacts(\App\Models\MetaAdsAccount $account): array
    {
        $strong = $this->seedMetaCampaign($account, 'Strong One');
        $zero = $this->seedMetaCampaign($account, 'Zero ' . self::XSS);
        $costly = $this->seedMetaCampaign($account, 'Costly One');
        $issue = $this->seedMetaCampaign($account, 'Issue One', ['effective_status' => 'WITH_ISSUES']);

        $this->seedMetaInsight($account, MetaAdsLevel::Campaign, $strong->id, '2026-10-02', 30_000_000);
        $this->seedMetaResult($account, MetaAdsLevel::Campaign, $strong->id, '2026-10-02', 3);
        $this->seedMetaInsight($account, MetaAdsLevel::Campaign, $zero->id, '2026-10-02', 80_000_000);
        $this->seedMetaInsight($account, MetaAdsLevel::Campaign, $costly->id, '2026-10-02', 40_000_000);
        $this->seedMetaResult($account, MetaAdsLevel::Campaign, $costly->id, '2026-10-02', 2);
        $this->seedMetaInsight($account, MetaAdsLevel::Campaign, $issue->id, '2026-10-02', 1_000_000);

        return compact('strong', 'zero', 'costly', 'issue');
    }

    private function cardHtml(string $html, string $type): string
    {
        $start = strpos($html, 'data-type="' . $type . '"');
        $this->assertNotFalse($start, "card [{$type}] is rendered");

        $next = strpos($html, 'data-role="recommendation"', $start);
        $end = strpos($html, 'data-role="recommendations-note"', $start);
        $end = $next === false ? $end : min($next, (int) $end);

        return substr($html, $start, $end === false ? null : $end - $start);
    }

    public function test_cards_show_title_evidence_basis_and_one_link_to_the_owning_page(): void
    {
        [$workspace, $business, , $rows] = $this->ready();

        $response = $this->get($this->metaUrl($workspace, $business, 'recommendations.index'));

        $response->assertOk();
        $html = $response->getContent();

        foreach (['zero_result_spend', 'cost_per_result_above_target', 'strong_performer', 'delivery_issue'] as $type) {
            $this->assertStringContainsString('data-type="' . $type . '"', $html, $type);
        }

        $costly = $this->cardHtml($html, 'cost_per_result_above_target');
        $this->assertStringContainsString('Costly One costs more per result than your target', $costly);
        $this->assertStringContainsString('data-role="recommendation-evidence"', $costly);
        $this->assertStringContainsString('Based on your cached Meta Ads data for the last 30 days; deterministic rule.', $costly);
        $this->assertStringContainsString(route('customer.workspaces.businesses.ads.meta.campaigns.show', [$workspace->uid, $business->uid, $rows['costly']->uid, 'period' => 'last_30']), html_entity_decode($costly));
        $this->assertSame(1, substr_count($costly, 'data-role="recommendation-action"'), 'exactly one link');

        $delivery = $this->cardHtml($html, 'delivery_issue');
        $this->assertStringContainsString('Meta reports its delivery status as', $delivery);
    }

    public function test_tones_are_neutral_amber_or_green_and_never_red(): void
    {
        [$workspace, $business] = $this->ready();

        $html = $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->getContent();

        $this->assertStringContainsString('data-type="strong_performer" data-tone="success"', $html);
        $this->assertStringContainsString('data-type="cost_per_result_above_target" data-tone="warning"', $html);
        $this->assertStringContainsString('data-type="zero_result_spend" data-tone="neutral"', $html);
        $this->assertStringContainsString('data-type="delivery_issue" data-tone="warning"', $html);

        $list = substr($html, (int) strpos($html, 'data-role="recommendation-list"'));
        $list = substr($list, 0, (int) strpos($list, 'data-role="recommendations-note"'));
        $this->assertStringNotContainsString('border-danger', $list);
        $this->assertStringNotContainsString('text-danger', $list);
        $this->assertStringNotContainsString('alert-danger', $list);
    }

    public function test_a_strong_performer_is_a_positive_card_with_no_action(): void
    {
        [$workspace, $business] = $this->ready();

        $html = $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->getContent();

        $strong = $this->cardHtml($html, 'strong_performer');
        $this->assertStringContainsString('Strong One is doing well against your target', $strong);
        $this->assertStringNotContainsString('data-role="recommendation-action"', $strong);
        $this->assertStringNotContainsString('<a ', $strong);
    }

    public function test_there_is_no_dismiss_snooze_or_apply_control_and_nothing_is_a_form(): void
    {
        [$workspace, $business] = $this->ready();

        $html = $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->getContent();
        $list = substr($html, (int) strpos($html, 'data-role="recommendation-list"'));
        $list = substr($list, 0, (int) strpos($list, 'data-role="recommendations-note"'));

        foreach (['dismiss', 'snooze', 'apply', 'pause', '<form', '<button'] as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $list, $word);
        }

        $this->assertStringContainsString('nothing is paused or edited from this page', $html);
    }

    public function test_customer_names_inside_the_wording_are_escaped(): void
    {
        [$workspace, $business] = $this->ready();

        $html = $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->getContent();

        $this->assertStringNotContainsString(self::XSS, $html);
        $this->assertStringContainsString('Zero &lt;script&gt;alert(1)&lt;/script&gt; has spend but no results yet', $html);
    }

    public function test_an_account_with_nothing_to_flag_explains_why_and_is_not_an_error(): void
    {
        [$workspace, $business] = $this->ready(seed: false);

        $response = $this->get($this->metaUrl($workspace, $business, 'recommendations.index'));

        $response->assertOk();
        $html = $response->getContent();
        $this->assertStringContainsString('data-role="no-recommendations"', $html);
        $this->assertStringContainsString('Nothing to flag right now', $html);
        $this->assertStringContainsString('enough spend and results data', $html);
        $this->assertStringNotContainsString('data-role="recommendation-list"', $html);
    }

    public function test_the_period_selector_is_honoured_and_a_hostile_period_falls_back(): void
    {
        [$workspace, $business] = $this->ready();

        $previous = $this->get($this->metaUrl($workspace, $business, 'recommendations.index', ['period' => 'previous_month']))->assertOk()->getContent();
        foreach (['strong_performer', 'zero_result_spend', 'cost_per_result_above_target'] as $type) {
            $this->assertStringNotContainsString('data-type="' . $type . '"', $previous, 'September holds no spend data: ' . $type);
        }
        $this->assertStringContainsString('data-type="delivery_issue"', $previous, 'a delivery issue is current status, not a period figure');
        $this->assertStringContainsString('data-period="previous_month"', $previous);

        $fallback = $this->get($this->metaUrl($workspace, $business, 'recommendations.index', ['period' => "'; DROP TABLE x;--"]))->assertOk()->getContent();
        $this->assertStringContainsString('data-type="strong_performer"', $fallback);
    }

    public function test_without_a_result_type_the_page_hints_and_result_based_cards_disappear(): void
    {
        [$workspace, $business] = $this->ready(accountOverrides: ['result_action_type' => null]);

        $html = $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->getContent();

        $this->assertStringContainsString('data-role="result-type-unset"', $html);
        $this->assertStringContainsString(route('customer.workspaces.businesses.ads.meta.settings', [$workspace->uid, $business->uid]), $html);
        foreach (['zero_result_spend', 'cost_per_result_above_target', 'strong_performer'] as $type) {
            $this->assertStringNotContainsString('data-type="' . $type . '"', $html, $type);
        }
        // A delivery issue needs no result type.
        $this->assertStringContainsString('data-type="delivery_issue"', $html);
    }

    public function test_with_a_result_type_the_hint_is_absent(): void
    {
        [$workspace, $business] = $this->ready();

        $this->assertStringNotContainsString('data-role="result-type-unset"', $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->getContent());
    }

    public function test_the_page_reads_cached_rows_only_and_leaks_no_provider_id(): void
    {
        [$workspace, $business, $account] = $this->ready();

        $html = $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->getContent();

        $this->assertSame(0, $this->fakeMeta->callCount());
        $this->assertStringNotContainsString((string) $account->ad_account_id, $html);
        $this->assertStringNotContainsString('camp-' . $account->id, $html);
    }

    public function test_states_not_connected_and_no_account_use_the_standard_empty_state(): void
    {
        [$customer, $business, $workspace] = $this->dataTenant(withAccount: false);
        $this->asMetaUser($customer);

        $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->assertOk()->assertSee('data-state="not_connected"', false);

        [$customer2, $business2, $workspace2] = $this->dataTenant(withAccount: false);
        $this->activeMetaConnection($business2);
        $this->asMetaUser($customer2);
        $this->get($this->metaUrl($workspace2, $business2, 'recommendations.index'))->assertOk()->assertSee('data-state="no_account"', false);
    }

    public function test_core_is_404_without_view_is_401_and_a_stranger_is_404(): void
    {
        [$workspace, $business] = $this->ready(tier: WorkspacePlanTier::Core);
        $this->get($this->metaUrl($workspace, $business, 'recommendations.index'))->assertNotFound();

        [$workspace2, $business2] = $this->ready(permissions: []);
        $this->get($this->metaUrl($workspace2, $business2, 'recommendations.index'))->assertStatus(401);

        [$stranger] = $this->dataTenant();
        $this->asMetaUser($stranger);
        $this->get($this->metaUrl($workspace2, $business2, 'recommendations.index'))->assertNotFound();
    }
}
