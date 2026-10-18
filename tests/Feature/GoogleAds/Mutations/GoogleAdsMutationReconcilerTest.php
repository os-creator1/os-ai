<?php

namespace Tests\Feature\GoogleAds\Mutations;

use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\GoogleAdsOperationLedger;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationOperations;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationReconciler;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationService;
use App\Library\GoogleAds\Mutations\MutationOutcomeStatus;
use App\Library\GoogleAds\Mutations\ReconcileMutationsAfterSync;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsMutation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\GoogleAds\Mutations\Concerns\CreatesMutationFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §6 step 5 — an `unknown` mutation is settled
 * from Google's synced state, never by re-sending. The "sync" here copies the
 * fake PROVIDER's real state into the local mirror, so applied / not-applied
 * is decided by what the provider actually holds.
 */
class GoogleAdsMutationReconcilerTest extends TestCase
{
    use CreatesMutationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeAds();
    }

    private function service(): GoogleAdsMutationService
    {
        return app(GoogleAdsMutationService::class);
    }

    private function reconciler(): GoogleAdsMutationReconciler
    {
        return app(GoogleAdsMutationReconciler::class);
    }

    private function operation(GoogleOperationType $type): BusinessGoogleOperation
    {
        return BusinessGoogleOperation::query()->where('operation_type', $type->value)->latest('id')->firstOrFail();
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function everyKindBothWays(): array
    {
        $cases = [];

        foreach (['campaign', 'keyword', 'negative'] as $kind) {
            $cases[$kind . ' applied at Google'] = [$kind, true];
            $cases[$kind . ' not applied at Google'] = [$kind, false];
        }

        return $cases;
    }

    #[DataProvider('everyKindBothWays')]
    public function test_an_unknown_mutation_is_resolved_from_synced_state_without_any_provider_call(string $kind, bool $applied): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $keyword = $this->positiveKeywordOf($t['account']);

        [$method, $type] = match ($kind) {
            'campaign' => ['setCampaignStatus', GoogleOperationType::AdsCampaignStatusChanged],
            'keyword' => ['setKeywordStatus', GoogleOperationType::AdsKeywordStatusChanged],
            'negative' => ['addNegativeKeyword', GoogleOperationType::AdsNegativeKeywordAdded],
        };

        $this->fakeAds->failNext($method, GoogleAdsProviderException::timeout(true), 1, $applied);

        $outcome = match ($kind) {
            'campaign' => $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid),
            'keyword' => $this->service()->pauseKeyword($t['business'], $t['actor'], $keyword->uid),
            'negative' => $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'photo booth jobs'),
        };

        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $outcome->status);
        $this->assertSame(GoogleOperationStatus::Unknown, $this->operation($type)->status);

        // Reconciling BEFORE any fresh sync is not evidence of anything.
        $this->reconciler()->reconcile($t['account']);
        $this->assertSame(GoogleOperationStatus::Unknown, $this->operation($type)->status);

        $this->syncFromFakeProvider($t['account']);
        $callsBefore = $this->fakeAds->callCount();

        $this->reconciler()->reconcile($t['account']);

        $this->assertSame($callsBefore, $this->fakeAds->callCount(), 'reconciliation never calls the provider');

        $resolved = $this->operation($type);

        if ($applied) {
            $this->assertSame(GoogleOperationStatus::Succeeded, $resolved->status);
        } else {
            $this->assertSame(GoogleOperationStatus::Failed, $resolved->status);
            $this->assertSame('not_applied', $resolved->failure_classification);
        }

        // Once settled, the user's next attempt reflects reality.
        $retry = match ($kind) {
            'campaign' => $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid),
            'keyword' => $this->service()->pauseKeyword($t['business'], $t['actor'], $keyword->uid),
            'negative' => $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'photo booth jobs'),
        };

        if ($applied) {
            $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $retry->status);
            $this->assertSame(1, $this->fakeAds->callCount($method));
        } else {
            $this->assertSame(MutationOutcomeStatus::Succeeded, $retry->status);
            $this->assertSame(2, $this->fakeAds->callCount($method));
        }
    }

    public function test_an_applied_negative_present_after_sync_resolves_succeeded_and_blocks_a_resend(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeAds->failNext('addNegativeKeyword', GoogleAdsProviderException::timeout(true), 1, true);

        $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'Photo Booth Jobs');
        $this->syncFromFakeProvider($t['account']);
        $this->reconciler()->reconcile($t['account']);

        $this->assertNotNull($this->negativeOf($t['account'], 'photo booth jobs'));
        $this->assertSame(GoogleOperationStatus::Succeeded, $this->operation(GoogleOperationType::AdsNegativeKeywordAdded)->status);

        $again = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'photo booth jobs');
        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $again->status);
        $this->assertSame(1, $this->fakeAds->callCount('addNegativeKeyword'));
    }

    public function test_absence_without_a_sync_newer_than_the_mutation_stays_unknown(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeAds->failNext('setCampaignStatus', GoogleAdsProviderException::timeout(true), 1, false);
        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        // The mirror was last synced BEFORE the mutate finished.
        $campaign->forceFill(['last_synced_at' => now()->subHour()])->save();
        $this->reconciler()->reconcile($t['account']);

        $this->assertSame(GoogleOperationStatus::Unknown, $this->operation(GoogleOperationType::AdsCampaignStatusChanged)->status);
    }

    public function test_a_negative_is_not_called_not_applied_after_the_campaigns_stage_or_a_truncated_keywords_report(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        // Applied at Google, but our keyword mirror has not been refreshed yet.
        $this->fakeAds->failNext('addNegativeKeyword', GoogleAdsProviderException::timeout(true), 1, true);
        $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'photo booth jobs');

        // The campaigns stage just ran (campaign stamp is newer than the op); keywords have not.
        $campaign->forceFill(['last_synced_at' => now()->addMinutes(5)])->save();
        $type = GoogleOperationType::AdsNegativeKeywordAdded;

        $this->reconciler()->reconcile($t['account'], 'campaigns');
        $this->assertSame(GoogleOperationStatus::Unknown, $this->operation($type)->status, 'campaigns stage: keywords not refreshed');

        $this->reconciler()->reconcile($t['account'], 'keywords', truncated: true);
        $this->assertSame(GoogleOperationStatus::Unknown, $this->operation($type)->status, 'truncated report: absence proves nothing');

        $this->reconciler()->reconcile($t['account'], 'keywords');
        $this->assertSame('not_applied', $this->operation($type)->failure_classification, 'complete keywords report: absence is evidence');
    }

    public function test_the_sync_observer_passes_the_stage_and_truncation_to_the_reconciler(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeAds->failNext('addNegativeKeyword', GoogleAdsProviderException::timeout(true), 1, true);
        $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'photo booth jobs');
        $campaign->forceFill(['last_synced_at' => now()->addMinutes(5)])->save();
        $observer = app(ReconcileMutationsAfterSync::class);
        $type = GoogleOperationType::AdsNegativeKeywordAdded;

        $observer->afterStage($t['account'], 'campaigns', false);
        $observer->afterStage($t['account'], 'keywords', true);
        $this->assertSame(GoogleOperationStatus::Unknown, $this->operation($type)->status);

        $observer->afterStage($t['account'], 'keywords', false);
        $this->assertSame(GoogleOperationStatus::Failed, $this->operation($type)->status);
    }

    public function test_an_applied_state_is_accepted_after_the_campaigns_stage_too(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeAds->failNext('setCampaignStatus', GoogleAdsProviderException::timeout(true), 1, true);
        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->syncFromFakeProvider($t['account']);

        $this->reconciler()->reconcile($t['account'], 'campaigns');

        $this->assertSame(GoogleOperationStatus::Succeeded, $this->operation(GoogleOperationType::AdsCampaignStatusChanged)->status);
    }

    public function test_an_operation_older_than_the_bound_without_evidence_stays_unknown(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeAds->failNext('setCampaignStatus', GoogleAdsProviderException::timeout(true), 1, false);
        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        Carbon::setTestNow(now()->addDays(4));
        try {
            $this->syncFromFakeProvider($t['account']);
            $this->reconciler()->reconcile($t['account']);

            $this->assertSame(GoogleOperationStatus::Unknown, $this->operation(GoogleOperationType::AdsCampaignStatusChanged)->status);

            config(['google_ads.mutations.reconcile_max_age_hours' => 24 * 10]);
            $this->reconciler()->reconcile($t['account']);

            $this->assertSame(GoogleOperationStatus::Failed, $this->operation(GoogleOperationType::AdsCampaignStatusChanged)->status);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_only_this_accounts_unknown_operations_are_touched(): void
    {
        $mine = $this->mutationTenant(name: 'Mine');
        $theirs = $this->mutationTenant(name: 'Theirs');

        $this->fakeAds->failNext('setCampaignStatus', GoogleAdsProviderException::timeout(true), 1, true);
        $this->service()->pauseCampaign($theirs['business'], $theirs['actor'], $this->campaignOf($theirs['account'])->uid);
        $this->syncFromFakeProvider($theirs['account']);

        $this->reconciler()->reconcile($mine['account']);

        $this->assertSame(GoogleOperationStatus::Unknown, $this->operation(GoogleOperationType::AdsCampaignStatusChanged)->status);

        $this->reconciler()->reconcile($theirs['account']);

        $this->assertSame(GoogleOperationStatus::Succeeded, $this->operation(GoogleOperationType::AdsCampaignStatusChanged)->status);
    }

    public function test_a_stale_pending_operation_is_moved_to_unknown_and_keeps_blocking_a_resend(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $operation = app(GoogleAdsOperationLedger::class)->open((int) $t['business']->id, GoogleOperationType::AdsCampaignStatusChanged, (int) $t['actor']->id, 'crashed before send');
        $operation->forceFill(['started_at' => now()->subMinutes(30)])->save();
        GoogleAdsMutation::create([
            'business_id' => $t['account']->business_id, 'google_ads_account_id' => $t['account']->id,
            'business_google_operation_id' => $operation->id, 'kind' => 'campaign_status', 'target_type' => 'campaign',
            'target_local_id' => $campaign->id, 'target_resource_name' => 'customers/x/campaigns/y',
            'requested_state' => 'PAUSED', 'dedupe_key' => hash('sha256', 'x'), 'actor_user_id' => $t['actor']->id,
        ]);

        $this->reconciler()->reconcile($t['account']);

        $this->assertSame(GoogleOperationStatus::Unknown, $operation->fresh()->status);

        $outcome = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $outcome->status);
        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));

        // A later sync shows it was not applied: resolved, and a new attempt may proceed.
        $this->syncFromFakeProvider($t['account']);
        $this->reconciler()->reconcile($t['account']);
        $this->assertSame(GoogleOperationStatus::Failed, $operation->fresh()->status);
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $campaign->fresh()->status);
    }

    public function test_reconciliation_leaves_non_unknown_operations_alone(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->fakeAds->failNext('addNegativeKeyword', GoogleAdsProviderException::rateLimited());
        $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'deferred one');

        $this->syncFromFakeProvider($t['account']);
        $this->reconciler()->reconcile($t['account']);

        $this->assertSame(
            [GoogleOperationStatus::Succeeded, GoogleOperationStatus::Deferred],
            BusinessGoogleOperation::query()->whereIn('operation_type', GoogleAdsMutationOperations::typeValues())->orderBy('id')->get()->map->status->all(),
        );
    }
}
