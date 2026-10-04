<?php

namespace Tests\Feature\MetaAds\Reporting;

use App\Enums\MetaAds\MetaAdsLevel;
use App\Enums\MetaAds\MetaAdsMutationTargetType;
use App\Enums\MetaAds\MetaAdsRequestedState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Library\MetaAds\Recommendations\MetaAdsRecommendationFactReader;
use App\Library\MetaAds\Reporting\MetaAdsAdReader;
use App\Library\MetaAds\Reporting\MetaAdsAdSetReader;
use App\Library\MetaAds\Reporting\MetaAdsBudgetReader;
use App\Library\MetaAds\Reporting\MetaAdsCampaignReader;
use App\Library\MetaAds\Reporting\MetaAdsOverviewReader;
use App\Library\MetaAds\Reporting\MetaAdsPendingConfirmations;
use App\Library\MetaAds\Reporting\MetaAdsPeriod;
use App\Library\MetaAds\Reporting\MetaAdsTrendSeries;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsMutation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\MetaAds\Reporting\Concerns\SeedsMetaAdsReportingData;
use Tests\TestCase;

/**
 * Contract 24 section 5 / 8 - Business B's data never appears for Business A,
 * a foreign uid is "not found", currencies never mix, and the pending-
 * confirmation overlay cannot be steered across Businesses. Two Businesses,
 * each with its own selected Meta account, live in the same database.
 */
class MetaAdsReportingIsolationTest extends TestCase
{
    use RefreshDatabase;
    use SeedsMetaAdsReportingData;

    private MetaAdsAccount $a;

    private MetaAdsAccount $b;

    private object $aCampaign;

    private object $bCampaign;

    private object $bAdSet;

    private object $bAd;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pinMetaClock();
        [, $businessA] = $this->metaReportingTenant('Business A');
        [, $businessB] = $this->metaReportingTenant('Business B');
        $this->a = $this->metaReportingAccountFor($businessA, ['target_cost_per_result_micros' => 10_000_000]);
        $this->b = $this->metaReportingAccountFor($businessB, ['currency_code' => 'EUR', 'target_cost_per_result_micros' => 10_000_000, 'monthly_budget_target_micros' => 100_000_000]);

        $this->aCampaign = $this->seedMetaCampaign($this->a, 'A campaign');
        $this->seedMetaDays($this->a, MetaAdsLevel::Campaign, $this->aCampaign->id, '2026-10-01', '2026-10-03', 10_000_000, 1);

        // Business B: bigger, loud data of every kind.
        $this->bCampaign = $this->seedMetaCampaign($this->b, 'B campaign', ['effective_status' => 'WITH_ISSUES']);
        $this->bAdSet = $this->seedMetaAdSet($this->bCampaign, 'B ad set', ['frequency_7d' => '5.0000']);
        $this->bAd = $this->seedMetaAd($this->bAdSet, 'B ad');
        $this->seedMetaDays($this->b, MetaAdsLevel::Campaign, $this->bCampaign->id, '2026-10-01', '2026-10-03', 900_000_000, null);
        $this->seedMetaDays($this->b, MetaAdsLevel::AdSet, $this->bAdSet->id, '2026-09-21', '2026-10-04', 100_000_000, null);
        $this->seedMetaDays($this->b, MetaAdsLevel::Ad, $this->bAd->id, '2026-10-01', '2026-10-03', 50_000_000, null);
        $this->seedMetaCampaign($this->b, 'B zero spender');
        $this->seedMetaResult($this->b, MetaAdsLevel::Campaign, $this->bCampaign->id, '2026-10-01', 9);
    }

    protected function tearDown(): void
    {
        $this->unpinMetaClock();
        parent::tearDown();
    }

    private function period(MetaAdsAccount $account)
    {
        return MetaAdsPeriod::resolve('last_30', $account);
    }

    public function test_business_a_readers_never_return_business_b_rows(): void
    {
        $p = $this->period($this->a);

        $campaigns = app(MetaAdsCampaignReader::class)->page($this->a, $p);
        $this->assertSame(['A campaign'], array_map(fn ($r) => $r->name, $campaigns->items));
        $this->assertSame(1, $campaigns->total);
        $this->assertSame(['A campaign'], array_values(app(MetaAdsCampaignReader::class)->options($this->a)));
        $this->assertSame(0, app(MetaAdsAdSetReader::class)->page($this->a, $p)->total);
        $this->assertSame([], app(MetaAdsAdSetReader::class)->options($this->a));
        $this->assertSame(0, app(MetaAdsAdReader::class)->page($this->a, $p)->total);

        $overview = app(MetaAdsOverviewReader::class)->read($this->a, $p);
        $this->assertSame(30_000_000, $overview->spendMicros);
        $this->assertSame('3.000000', $overview->results);
        $this->assertSame('USD', $overview->currencyCode);

        $trend = app(MetaAdsTrendSeries::class)->forPeriod($this->a, $p);
        $this->assertSame(30_000_000, array_sum($trend['series']['spend_micros']));

        $facts = app(MetaAdsRecommendationFactReader::class)->facts($this->a, $p);
        $this->assertSame(['strong_performer'], array_map(fn ($f) => $f->type->value, $facts));   // A's own campaign only
        $this->assertSame($this->aCampaign->uid, $facts[0]->subjectUid);
        $bTypes = array_map(fn ($f) => $f->type->value . ':' . $f->subjectUid, app(MetaAdsRecommendationFactReader::class)->facts($this->b, $this->period($this->b)));
        $this->assertContains('delivery_issue:' . $this->bCampaign->uid, $bTypes);
        $this->assertNotContains('strong_performer:' . $this->aCampaign->uid, $bTypes);
        $this->assertSame(30_000_000, app(MetaAdsBudgetReader::class)->read($this->a)->campaigns[0]->spendMicros());
        $this->assertCount(1, app(MetaAdsBudgetReader::class)->read($this->a)->campaigns);

        // And the other direction: B sees none of A's.
        $bCampaigns = app(MetaAdsCampaignReader::class)->page($this->b, $this->period($this->b));
        $this->assertSame(['B campaign', 'B zero spender'], array_map(fn ($r) => $r->name, $bCampaigns->items));
        $bOverview = app(MetaAdsOverviewReader::class)->read($this->b, $this->period($this->b));
        $this->assertSame(2_700_000_000, $bOverview->spendMicros);   // EUR 2,700; none of A's 30
        $this->assertSame('EUR', $bOverview->currencyCode);
    }

    public function test_a_foreign_uid_is_not_found_by_every_reader(): void
    {
        $p = $this->period($this->a);

        $this->assertNull(app(MetaAdsCampaignReader::class)->find($this->a, $this->bCampaign->uid, $p));
        $this->assertNull(app(MetaAdsCampaignReader::class)->detail($this->a, $this->bCampaign->uid, $p));
        $this->assertNull(app(MetaAdsAdSetReader::class)->find($this->a, $this->bAdSet->uid, $p));
        $this->assertNull(app(MetaAdsAdReader::class)->find($this->a, $this->bAd->uid, $p));

        // A foreign uid as a FILTER narrows to nothing; it never widens to B's rows.
        $this->assertSame(0, app(MetaAdsAdSetReader::class)->page($this->a, $p, null, $this->bCampaign->uid)->total);
        $this->assertSame(0, app(MetaAdsAdReader::class)->page($this->a, $p, null, $this->bCampaign->uid)->total);
        $this->assertSame(0, app(MetaAdsAdReader::class)->page($this->a, $p, null, null, $this->bAdSet->uid)->total);

        $trend = app(MetaAdsTrendSeries::class)->forPeriod($this->a, $p, $this->bCampaign->uid);
        $this->assertFalse($trend['has_data']);
        $this->assertSame(0, array_sum($trend['series']['spend_micros']));

        // The query-input whitelist drops it as well (options only hold A's own uids).
        $this->assertNull(\App\Library\MetaAds\Reporting\MetaAdsListInput::entity($this->bCampaign->uid, app(MetaAdsCampaignReader::class)->options($this->a)));

        // And the filter value is bound, never concatenated.
        $this->assertSame(0, app(MetaAdsAdSetReader::class)->page($this->a, $p, null, "x' OR '1'='1")->total);
    }

    public function test_a_row_planted_under_the_wrong_business_id_does_not_leak(): void
    {
        // Account scope is the boundary: a daily row written under A's account (even one whose
        // entity id happens to equal B's campaign id) is only ever summed for A, never for B,
        // and never joins B's campaign table.
        $this->seedMetaInsight($this->a, MetaAdsLevel::Campaign, $this->bCampaign->id, '2026-10-02', 777_000_000);

        $a = app(MetaAdsOverviewReader::class)->read($this->a, $this->period($this->a));
        $b = app(MetaAdsOverviewReader::class)->read($this->b, $this->period($this->b));

        $this->assertSame(807_000_000, $a->spendMicros);                // A's own scope: its own account rows only
        $this->assertSame(2_700_000_000, $b->spendMicros);              // B untouched by A's row
        // And the campaign table of A (joined through A's campaign table) shows no B campaign.
        $this->assertSame(['A campaign'], array_map(fn ($r) => $r->name, app(MetaAdsCampaignReader::class)->page($this->a, $this->period($this->a))->items));
    }

    public function test_pacing_and_targets_are_per_business(): void
    {
        $this->assertNull(app(MetaAdsBudgetReader::class)->pacing($this->a)->monthlyTargetMicros);
        $this->assertSame(100_000_000, app(MetaAdsBudgetReader::class)->pacing($this->b)->monthlyTargetMicros);
    }

    public function test_pending_confirmation_overlay_is_scoped_and_bounded(): void
    {
        $aOp = $this->ledgerOperation($this->a, MetaOperationStatus::Unknown);
        $this->mutationFor($this->a, $aOp, MetaAdsMutationTargetType::Campaign, $this->aCampaign->id);

        $bOp = $this->ledgerOperation($this->b, MetaOperationStatus::Unknown);
        $this->mutationFor($this->b, $bOp, MetaAdsMutationTargetType::Campaign, $this->bCampaign->id);
        $bAdSetOp = $this->ledgerOperation($this->b, MetaOperationStatus::Unknown);
        $this->mutationFor($this->b, $bAdSetOp, MetaAdsMutationTargetType::AdSet, $this->bAdSet->id);
        $bDone = $this->ledgerOperation($this->b, MetaOperationStatus::Succeeded);
        $this->mutationFor($this->b, $bDone, MetaAdsMutationTargetType::Ad, $this->bAd->id);

        $pending = app(MetaAdsPendingConfirmations::class);

        $this->assertSame([$this->aCampaign->uid => true], $pending->forCampaigns($this->a, [$this->aCampaign->uid]));
        $this->assertSame([], $pending->forCampaigns($this->a, [$this->bCampaign->uid]));          // foreign uid: nothing
        $this->assertSame([$this->bCampaign->uid => true], $pending->forCampaigns($this->b, [$this->bCampaign->uid, $this->aCampaign->uid, 'junk']));
        $this->assertSame([$this->bAdSet->uid => true], $pending->forAdSets($this->b, [$this->bAdSet->uid]));
        $this->assertSame([], $pending->forAds($this->b, [$this->bAd->uid]));                      // settled (succeeded): not pending
        $this->assertSame([], $pending->forAdSets($this->a, [$this->bAdSet->uid]));
        $this->assertSame([], $pending->forCampaigns($this->a, []));
        // A target type mismatch (campaign op asked as ad set) is not pending.
        $this->assertSame([], $pending->forAdSets($this->a, [$this->aCampaign->uid]));

        DB::enableQueryLog();
        $pending->forCampaigns($this->b, [$this->bCampaign->uid, $this->aCampaign->uid]);
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_a_forged_mutation_row_cannot_borrow_another_businesses_unknown_operation(): void
    {
        // A mutation row of A pointing at B's ledger op (which is `unknown`): the ledger row's
        // business_id must match, so A's campaign is NOT pending.
        $bOp = $this->ledgerOperation($this->b, MetaOperationStatus::Unknown);
        MetaAdsMutation::create([
            'business_id' => $this->a->business_id,
            'meta_ads_account_id' => $this->a->id,
            'business_meta_operation_id' => $bOp->id,
            'target_type' => MetaAdsMutationTargetType::Campaign,
            'target_local_id' => $this->aCampaign->id,
            'requested_state' => MetaAdsRequestedState::Paused,
            'dedupe_key' => str_repeat('a', 64),
        ]);

        $this->assertSame([], app(MetaAdsPendingConfirmations::class)->forCampaigns($this->a, [$this->aCampaign->uid]));
    }

    private function ledgerOperation(MetaAdsAccount $account, MetaOperationStatus $status): BusinessMetaOperation
    {
        return BusinessMetaOperation::create([
            'business_id' => $account->business_id,
            'operation_type' => MetaOperationType::CampaignStatusChanged,
            'local_operation_key' => 'test:' . uniqid('', true),
            'provider_call_count' => 1,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    private function mutationFor(MetaAdsAccount $account, BusinessMetaOperation $operation, MetaAdsMutationTargetType $type, int $localId): MetaAdsMutation
    {
        return MetaAdsMutation::create([
            'business_id' => $account->business_id,
            'meta_ads_account_id' => $account->id,
            'business_meta_operation_id' => $operation->id,
            'target_type' => $type,
            'target_local_id' => $localId,
            'requested_state' => MetaAdsRequestedState::Paused,
            'dedupe_key' => hash('sha256', uniqid('', true)),
        ]);
    }
}
