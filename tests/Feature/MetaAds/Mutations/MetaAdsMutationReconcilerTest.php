<?php

namespace Tests\Feature\MetaAds\Mutations;

use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\MetaAdsOperationLedger;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Library\MetaAds\Mutations\MetaAdsMutationOutcome;
use App\Library\MetaAds\Mutations\MetaAdsMutationOutcomeStatus;
use App\Library\MetaAds\Mutations\MetaAdsMutationReconciler;
use App\Library\MetaAds\Mutations\MetaAdsMutationService;
use App\Library\MetaAds\Mutations\ReconcileMetaMutationsAfterSync;
use App\Library\MetaAds\Sync\MetaAdsSyncCoordinator;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsMutation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\MetaAds\Mutations\Concerns\CreatesMetaMutationFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §7 — an `unknown` mutation is settled from
 * Meta's synced state, never by re-sending. The "sync" here copies the fake
 * PROVIDER's real state into the local mirror, so applied / not-applied is
 * decided by what the provider actually holds.
 */
class MetaAdsMutationReconcilerTest extends TestCase
{
    use CreatesMetaMutationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeMeta();
    }

    private function service(): MetaAdsMutationService
    {
        return app(MetaAdsMutationService::class);
    }

    private function reconciler(): MetaAdsMutationReconciler
    {
        return app(MetaAdsMutationReconciler::class);
    }

    private function operation(MetaOperationType $type): BusinessMetaOperation
    {
        return BusinessMetaOperation::query()->where('operation_type', $type->value)->latest('id')->firstOrFail();
    }

    /** @param  array<string, mixed>  $t */
    private function attempt(array $t, string $kind, string $verb = 'pause'): MetaAdsMutationOutcome
    {
        [$method, $row] = match ($kind) {
            'campaign' => [$verb . 'Campaign', $this->campaignOf($t['account'])],
            'ad_set' => [$verb . 'AdSet', $this->adSetOf($t['account'])],
            'ad' => [$verb . 'Ad', $this->adOf($t['account'])],
        };

        return $this->service()->{$method}($t['business'], $t['workspace'], (int) $t['actor']->id, $row->uid);
    }

    private function typeOf(string $kind): MetaOperationType
    {
        return match ($kind) {
            'campaign' => MetaOperationType::CampaignStatusChanged,
            'ad_set' => MetaOperationType::AdSetStatusChanged,
            'ad' => MetaOperationType::AdStatusChanged,
        };
    }

    private function stageOf(string $kind): string
    {
        return match ($kind) {
            'campaign' => 'campaigns',
            'ad_set' => 'ad_sets',
            'ad' => 'ads',
        };
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function everyKindBothWays(): array
    {
        $cases = [];

        foreach (['campaign', 'ad_set', 'ad'] as $kind) {
            $cases[$kind . ' applied at Meta'] = [$kind, true];
            $cases[$kind . ' not applied at Meta'] = [$kind, false];
        }

        return $cases;
    }

    #[DataProvider('everyKindBothWays')]
    public function test_an_unknown_mutation_is_resolved_from_synced_state_without_any_provider_call(string $kind, bool $applied): void
    {
        $t = $this->mutationTenant();
        $type = $this->typeOf($kind);
        $stage = $this->stageOf($kind);

        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, $applied);

        $outcome = $this->attempt($t, $kind);

        $this->assertSame(MetaAdsMutationOutcomeStatus::AwaitingConfirmation, $outcome->status);
        $this->assertSame(MetaOperationStatus::Unknown, $this->operation($type)->status);

        // Reconciling BEFORE any fresh sync is not evidence of anything.
        $this->reconciler()->reconcile($t['account'], $stage, false);
        $this->assertSame(MetaOperationStatus::Unknown, $this->operation($type)->status);

        $this->syncFromFakeProvider($t['account']);
        $callsBefore = $this->fakeMeta->callCount();

        $this->reconciler()->reconcile($t['account'], $stage, false);

        $this->assertSame($callsBefore, $this->fakeMeta->callCount(), 'reconciliation never calls the provider');

        $resolved = $this->operation($type);

        if ($applied) {
            $this->assertSame(MetaOperationStatus::Succeeded, $resolved->status);
        } else {
            $this->assertSame(MetaOperationStatus::Failed, $resolved->status);
            $this->assertSame('not_applied', $resolved->failure_classification);
        }

        // Once settled, the user's next attempt reflects reality.
        $retry = $this->attempt($t, $kind);

        if ($applied) {
            $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $retry->status);
            $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
        } else {
            $this->assertSame(MetaAdsMutationOutcomeStatus::Succeeded, $retry->status);
            $this->assertSame(2, $this->fakeMeta->callCount('setStatus'));
        }
    }

    public function test_an_applied_state_is_evidence_at_any_stage_but_absence_only_at_the_matching_complete_stage(): void
    {
        $t = $this->mutationTenant();

        // Campaign NOT applied; an ads-stage refresh says nothing about campaigns.
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, false);
        $this->attempt($t, 'campaign');
        $this->syncFromFakeProvider($t['account']);

        $this->reconciler()->reconcile($t['account'], 'ads', false);
        $this->assertSame(MetaOperationStatus::Unknown, $this->operation(MetaOperationType::CampaignStatusChanged)->status);

        $this->reconciler()->reconcile($t['account'], 'ad_sets', false);
        $this->assertSame(MetaOperationStatus::Unknown, $this->operation(MetaOperationType::CampaignStatusChanged)->status);

        $this->reconciler()->reconcile($t['account'], 'campaigns', false);
        $this->assertSame(MetaOperationStatus::Failed, $this->operation(MetaOperationType::CampaignStatusChanged)->status);
    }

    public function test_a_truncated_report_never_concludes_not_applied_but_still_confirms_applied(): void
    {
        $t = $this->mutationTenant();

        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, false);
        $this->attempt($t, 'ad_set');
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, true);
        $this->attempt($t, 'ad');
        $this->syncFromFakeProvider($t['account']);

        $this->reconciler()->reconcile($t['account'], 'ad_sets', true);
        $this->reconciler()->reconcile($t['account'], 'ads', true);

        $this->assertSame(MetaOperationStatus::Unknown, $this->operation(MetaOperationType::AdSetStatusChanged)->status, 'absence in a truncated report is no evidence');
        $this->assertSame(MetaOperationStatus::Succeeded, $this->operation(MetaOperationType::AdStatusChanged)->status, 'presence is evidence even when truncated');

        $this->reconciler()->reconcile($t['account'], 'ad_sets', false);
        $this->assertSame(MetaOperationStatus::Failed, $this->operation(MetaOperationType::AdSetStatusChanged)->status);
    }

    public function test_a_sync_that_finished_before_the_operation_is_not_evidence(): void
    {
        $t = $this->mutationTenant();
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, false);
        $this->attempt($t, 'campaign');

        // The mirror was last synced BEFORE the mutate completed.
        $this->syncFromFakeProvider($t['account'], now()->subMinutes(10));
        $this->reconciler()->reconcile($t['account'], 'campaigns', false);

        $this->assertSame(MetaOperationStatus::Unknown, $this->operation(MetaOperationType::CampaignStatusChanged)->status);
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function seedOperation(array $t, MetaOperationStatus $status, string $startedAgo, string $requested = 'paused'): BusinessMetaOperation
    {
        $campaign = $this->campaignOf($t['account']);
        $operation = app(MetaAdsOperationLedger::class)->open((int) $t['business']->id, MetaOperationType::CampaignStatusChanged, (int) $t['actor']->id, 'seed');
        $operation->forceFill(['status' => $status, 'started_at' => now()->sub($startedAgo)])->save();

        MetaAdsMutation::create([
            'business_id' => $t['account']->business_id, 'meta_ads_account_id' => $t['account']->id,
            'business_meta_operation_id' => $operation->id, 'target_type' => 'campaign',
            'target_local_id' => $campaign->id, 'requested_state' => $requested,
            'dedupe_key' => hash('sha256', uniqid('', true)), 'actor_user_id' => $t['actor']->id,
        ]);

        return $operation;
    }

    public function test_a_stale_pending_operation_is_moved_to_unknown_and_then_judged(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $fresh = $this->seedOperation($t, MetaOperationStatus::Pending, '5 minutes');

        $this->reconciler()->reconcile($t['account'], 'campaigns', false);
        $this->assertSame(MetaOperationStatus::Pending, $fresh->fresh()->status, 'a recent pending operation is still in flight');
        $fresh->forceFill(['started_at' => now()->subMinutes(20)])->save();

        $this->reconciler()->reconcile($t['account'], 'campaigns', false);
        $this->assertSame(MetaOperationStatus::Unknown, $fresh->fresh()->status, 'process died between commit and send: unknowable');

        // It now blocks its target as awaiting confirmation...
        $blocked = $this->service()->pauseCampaign($t['business'], $t['workspace'], (int) $t['actor']->id, $campaign->uid);
        $this->assertSame(MetaAdsMutationOutcomeStatus::AwaitingConfirmation, $blocked->status);
        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));

        // ...until a later complete sync shows the state (here: still ACTIVE => not applied).
        $this->syncFromFakeProvider($t['account']);
        $this->reconciler()->reconcile($t['account'], 'campaigns', false);
        $this->assertSame(MetaOperationStatus::Failed, $fresh->fresh()->status);
        $this->assertSame('not_applied', $fresh->fresh()->failure_classification);
    }

    public function test_operations_older_than_72_hours_are_left_unknown(): void
    {
        $t = $this->mutationTenant();
        $old = $this->seedOperation($t, MetaOperationStatus::Unknown, '80 hours');
        $old->forceFill(['completed_at' => now()->subHours(79)])->save();

        // The state is in sync (and NOT the requested one) after the operation: would be "not applied" if young.
        $this->syncFromFakeProvider($t['account']);
        $this->reconciler()->reconcile($t['account'], 'campaigns', false);

        $this->assertSame(MetaOperationStatus::Unknown, $old->fresh()->status);

        // Even a matching state does not resurrect evidence after 72 hours.
        $this->campaignOf($t['account'])->forceFill(['status' => 'PAUSED'])->save();
        $this->reconciler()->reconcile($t['account'], 'campaigns', false);
        $this->assertSame(MetaOperationStatus::Unknown, $old->fresh()->status);
    }

    public function test_another_businesss_unknown_operations_are_not_touched(): void
    {
        $mine = $this->mutationTenant(name: 'Mine');
        $theirs = $this->mutationTenant(name: 'Theirs');

        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, true);
        $this->attempt($theirs, 'campaign');
        $this->syncFromFakeProvider($theirs['account']);

        $this->reconciler()->reconcile($mine['account'], 'campaigns', false);

        $this->assertSame(MetaOperationStatus::Unknown, $this->operation(MetaOperationType::CampaignStatusChanged)->status);

        $this->reconciler()->reconcile($theirs['account'], 'campaigns', false);
        $this->assertSame(MetaOperationStatus::Succeeded, $this->operation(MetaOperationType::CampaignStatusChanged)->status);
    }

    public function test_the_after_sync_observer_reconciles_on_entity_stages_only_and_is_tagged_on_the_coordinator(): void
    {
        $t = $this->mutationTenant();
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, true);
        $this->attempt($t, 'campaign');
        $this->syncFromFakeProvider($t['account']);

        $observer = app(ReconcileMetaMutationsAfterSync::class);

        $observer->afterStage($t['account'], 'campaign_insights', false);
        $observer->afterStage($t['account'], 'account', false);
        $this->assertSame(MetaOperationStatus::Unknown, $this->operation(MetaOperationType::CampaignStatusChanged)->status);

        $observer->afterStage($t['account'], 'campaigns', false);
        $this->assertSame(MetaOperationStatus::Succeeded, $this->operation(MetaOperationType::CampaignStatusChanged)->status);

        $tagged = iterator_to_array(app()->tagged(MetaAdsSyncCoordinator::OBSERVER_TAG), false);
        $this->assertCount(1, array_filter($tagged, static fn ($o): bool => $o instanceof ReconcileMetaMutationsAfterSync));
    }

    public function test_the_pending_confirmation_overlay_reads_unknown_operations_and_clears_once_settled(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $reader = app(\App\Library\MetaAds\Reporting\MetaAdsPendingConfirmations::class);

        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, true);
        $this->attempt($t, 'campaign');

        $this->assertSame([$campaign->uid => true], $reader->forCampaigns($t['account'], [$campaign->uid]));
        $this->assertSame([], $reader->forAdSets($t['account'], [$this->adSetOf($t['account'])->uid]));

        $this->syncFromFakeProvider($t['account']);
        $this->reconciler()->reconcile($t['account'], 'campaigns', false);

        $this->assertSame([], $reader->forCampaigns($t['account'], [$campaign->uid]));
    }
}
