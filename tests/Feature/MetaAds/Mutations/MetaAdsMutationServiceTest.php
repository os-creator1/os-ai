<?php

namespace Tests\Feature\MetaAds\Mutations;

use App\DTO\MetaAds\MetaMutationResult;
use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaAdsMutationValidationException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\MetaAds\Contracts\MetaMutationClient;
use App\Library\MetaAds\MetaAdsOperationLedger;
use App\Library\MetaAds\MetaPhotoBoothFixture;
use App\Library\MetaAds\Mutations\MetaAdsMutationOutcome;
use App\Library\MetaAds\Mutations\MetaAdsMutationOutcomeStatus;
use App\Library\MetaAds\Mutations\MetaAdsMutationService;
use App\Models\BusinessMetaConnection;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use App\Models\MetaAdsMutation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\MetaAds\Mutations\Concerns\CreatesMetaMutationFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 contract 24 §7 — pause / resume of campaigns, ad sets
 * and ads: ledger / detail rows, local state, duplicate suppression and every
 * provider outcome. The FAKE provider only: no real request is ever made.
 */
class MetaAdsMutationServiceTest extends TestCase
{
    use CreatesMetaMutationFixtures;
    use RefreshDatabase;

    private const AD_ACCOUNT = MetaPhotoBoothFixture::AD_ACCOUNT_ID;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeMeta();
    }

    private function service(): MetaAdsMutationService
    {
        return app(MetaAdsMutationService::class);
    }

    /** @param  array<string, mixed>  $t */
    private function act(array $t, string $method, string $uid): MetaAdsMutationOutcome
    {
        return $this->service()->{$method}($t['business'], $t['workspace'], (int) $t['actor']->id, $uid);
    }

    private function lastOperation(MetaOperationType $type): BusinessMetaOperation
    {
        return BusinessMetaOperation::query()->where('operation_type', $type->value)->latest('id')->firstOrFail();
    }

    private function mutationOperations(): int
    {
        return BusinessMetaOperation::query()->whereIn('operation_type', [
            MetaOperationType::CampaignStatusChanged->value,
            MetaOperationType::AdSetStatusChanged->value,
            MetaOperationType::AdStatusChanged->value,
        ])->count();
    }

    /** @return array<string, array{0: string, 1: string, 2: string, 3: MetaOperationType, 4: string}> */
    public static function targets(): array
    {
        return [
            'campaign' => ['campaign', 'campaignOf', 'campaign', MetaOperationType::CampaignStatusChanged, 'Campaign'],
            'ad set' => ['ad_set', 'adSetOf', 'adSet', MetaOperationType::AdSetStatusChanged, 'AdSet'],
            'ad' => ['ad', 'adOf', 'ad', MetaOperationType::AdStatusChanged, 'Ad'],
        ];
    }

    private function providerStatus(string $type, string $external): ?string
    {
        return match ($type) {
            'campaign' => $this->fakeMeta->campaignStatus(self::AD_ACCOUNT, $external),
            'ad_set' => $this->fakeMeta->adSetStatus(self::AD_ACCOUNT, $external),
            default => $this->fakeMeta->adStatus(self::AD_ACCOUNT, $external),
        };
    }

    private function externalOf(string $type, $row): string
    {
        return (string) match ($type) {
            'campaign' => $row->external_campaign_id,
            'ad_set' => $row->external_ad_set_id,
            default => $row->external_ad_id,
        };
    }

    // ---------------------------------------------------------------
    // Success paths
    // ---------------------------------------------------------------

    #[DataProvider('targets')]
    public function test_pause_then_resume_calls_the_provider_once_each_and_updates_ledger_detail_and_local_state(string $type, string $finder, string $_k, MetaOperationType $opType, string $suffix): void
    {
        $t = $this->mutationTenant();
        $row = $this->{$finder}($t['account']);
        $external = $this->externalOf($type, $row);

        $pause = $this->act($t, 'pause' . $suffix, $row->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Succeeded, $pause->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_SUCCEEDED, $pause->messageKey);

        $calls = $this->fakeMeta->callsTo('setStatus');
        $this->assertCount(1, $calls);
        // The external id came from OUR row, derived server-side.
        $this->assertSame($external, $calls[0]['args']['external_id']);
        $this->assertSame($type, $calls[0]['args']['target_type']);
        $this->assertSame('PAUSED', $calls[0]['args']['requested_state']);
        $this->assertSame(self::AD_ACCOUNT, $calls[0]['ad_account_id']);

        $this->assertSame('PAUSED', $row->fresh()->status);
        $this->assertSame('PAUSED', $this->providerStatus($type, $external));

        $operation = $this->lastOperation($opType);
        $this->assertSame($pause->operationUid, $operation->uid);
        $this->assertSame(MetaOperationStatus::Succeeded, $operation->status);
        $this->assertSame((int) $t['actor']->id, (int) $operation->actor_user_id);
        $this->assertSame((int) $t['business']->id, (int) $operation->business_id);
        $this->assertNotNull($operation->completed_at);
        $this->assertStringContainsString('Pause', (string) $operation->summary);
        $this->assertNull($operation->provider_operation_reference);
        $this->assertSame(1, (int) $operation->provider_call_count);

        $detail = MetaAdsMutation::query()->where('business_meta_operation_id', $operation->id)->sole();
        $this->assertSame($type, $detail->target_type->value);
        $this->assertSame((int) $row->id, (int) $detail->target_local_id);
        $this->assertSame('paused', $detail->requested_state->value);
        $this->assertSame((int) $t['actor']->id, (int) $detail->actor_user_id);
        $this->assertSame(64, strlen($detail->dedupe_key));
        $this->assertSame((int) $t['account']->id, (int) $detail->meta_ads_account_id);
        $this->assertSame((int) $t['business']->id, (int) $detail->business_id);

        $resume = $this->act($t, 'resume' . $suffix, $row->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Succeeded, $resume->status);
        $this->assertSame(2, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame('ACTIVE', $row->fresh()->status);
        $this->assertSame('ACTIVE', $this->providerStatus($type, $external));
        $this->assertSame(2, $this->mutationOperations());
        $this->assertSame(2, MetaAdsMutation::query()->count());
    }

    public function test_pausing_a_campaign_leaves_child_ad_sets_and_ads_untouched_locally(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $adSet = $this->adSetOf($t['account']);
        $ad = $this->adOf($t['account']);

        $this->service()->pauseCampaign($t['business'], $t['workspace'], (int) $t['actor']->id, $campaign->uid);

        $this->assertSame('PAUSED', $campaign->fresh()->status);
        $this->assertSame('ACTIVE', $adSet->fresh()->status);
        $this->assertSame('ACTIVE', $adSet->fresh()->effective_status);
        $this->assertSame('ACTIVE', $ad->fresh()->status);
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
    }

    public function test_an_ad_set_under_a_paused_campaign_may_be_resumed(): void
    {
        $t = $this->mutationTenant();
        // Fixture: the Awareness ad set is PAUSED under the PAUSED Awareness campaign.
        $adSet = $this->adSetOf($t['account'], MetaPhotoBoothFixture::AD_SET_AWARENESS);
        $this->assertSame('PAUSED', $this->campaignOf($t['account'], MetaPhotoBoothFixture::CAMPAIGN_AWARENESS)->status);

        $outcome = $this->act($t, 'resumeAdSet', $adSet->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Succeeded, $outcome->status);
        $this->assertSame('ACTIVE', $adSet->fresh()->status);
    }

    // ---------------------------------------------------------------
    // Transitions
    // ---------------------------------------------------------------

    #[DataProvider('targets')]
    public function test_already_in_the_requested_state_is_a_noop_without_a_call(string $type, string $finder, string $_k, MetaOperationType $opType, string $suffix): void
    {
        $t = $this->mutationTenant();
        $row = $this->{$finder}($t['account']); // ACTIVE in the fixture

        $resume = $this->act($t, 'resume' . $suffix, $row->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $resume->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_ALREADY_IN_STATE, $resume->messageKey);
        $this->assertNull($resume->operationUid);

        $row->forceFill(['status' => 'PAUSED'])->save();
        $pause = $this->act($t, 'pause' . $suffix, $row->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $pause->status);
        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame(0, $this->mutationOperations());
    }

    #[DataProvider('targets')]
    public function test_deleted_or_archived_targets_are_refused_for_both_directions_without_a_call(string $type, string $finder, string $_k, MetaOperationType $opType, string $suffix): void
    {
        foreach (['DELETED', 'ARCHIVED'] as $status) {
            $t = $this->mutationTenant(name: 'Co ' . $status);
            $row = $this->{$finder}($t['account']);
            $row->forceFill(['status' => $status])->save();

            foreach (['pause', 'resume'] as $verb) {
                try {
                    $this->act($t, $verb . $suffix, $row->uid);
                    $this->fail('expected a validation refusal');
                } catch (MetaAdsMutationValidationException $e) {
                    $this->assertSame(422, $e->httpStatus());
                    $this->assertSame('entity_not_changeable', $e->reason);
                }
            }
        }

        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame(0, $this->mutationOperations());
    }

    /** @return array<string, array{0: string}> */
    public static function unapprovedEffectiveStatuses(): array
    {
        return ['disapproved' => ['DISAPPROVED'], 'pending review' => ['PENDING_REVIEW']];
    }

    #[DataProvider('unapprovedEffectiveStatuses')]
    public function test_an_unapproved_ad_cannot_be_resumed_but_can_be_paused(string $effective): void
    {
        $t = $this->mutationTenant();
        $ad = $this->adOf($t['account'], MetaPhotoBoothFixture::AD_DISAPPROVED);
        $ad->forceFill(['status' => 'PAUSED', 'effective_status' => $effective])->save();

        try {
            $this->act($t, 'resumeAd', $ad->uid);
            $this->fail('expected a validation refusal');
        } catch (MetaAdsMutationValidationException $e) {
            $this->assertSame('ad_not_resumable', $e->reason);
        }

        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame(0, $this->mutationOperations());

        // An ACTIVE disapproved ad is simply "already active", and pausing it is allowed.
        $ad->forceFill(['status' => 'ACTIVE'])->save();
        $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $this->act($t, 'resumeAd', $ad->uid)->status);
        $this->assertSame(MetaAdsMutationOutcomeStatus::Succeeded, $this->act($t, 'pauseAd', $ad->uid)->status);
    }

    // ---------------------------------------------------------------
    // Duplicates / in flight
    // ---------------------------------------------------------------

    public function test_a_double_submitted_pause_sends_one_provider_call(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $first = $this->act($t, 'pauseCampaign', $campaign->uid);
        $second = $this->act($t, 'pauseCampaign', $campaign->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Succeeded, $first->status);
        $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $second->status);
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame(1, $this->mutationOperations());
    }

    public function test_a_pending_operation_for_the_same_target_is_returned_not_re_sent(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $seed = $this->seedLedgerOperation($t, $campaign->id, MetaOperationStatus::Pending, 'paused');

        $outcome = $this->act($t, 'pauseCampaign', $campaign->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $outcome->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_IN_PROGRESS, $outcome->messageKey);
        $this->assertSame($seed->uid, $outcome->operationUid);
        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));

        // The opposite state is blocked too until the in-flight change settles.
        $resume = $this->act($t, 'resumeCampaign', $campaign->uid);
        $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $resume->status);
        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));
    }

    /** @param  array<string, mixed>  $t */
    private function seedLedgerOperation(array $t, int $campaignId, MetaOperationStatus $status, string $requested): BusinessMetaOperation
    {
        $operation = app(MetaAdsOperationLedger::class)->open((int) $t['business']->id, MetaOperationType::CampaignStatusChanged, (int) $t['actor']->id, 'seed');
        $operation->forceFill(['status' => $status])->save();

        MetaAdsMutation::create([
            'business_id' => $t['account']->business_id, 'meta_ads_account_id' => $t['account']->id,
            'business_meta_operation_id' => $operation->id, 'target_type' => 'campaign',
            'target_local_id' => $campaignId, 'requested_state' => $requested,
            'dedupe_key' => hash('sha256', uniqid('', true)), 'actor_user_id' => $t['actor']->id,
        ]);

        return $operation;
    }

    // ---------------------------------------------------------------
    // Provider outcomes
    // ---------------------------------------------------------------

    /** @return array<string, array{0: bool}> */
    public static function applyBeforeFailing(): array
    {
        return ['applied then timed out' => [true], 'timed out before applying' => [false]];
    }

    #[DataProvider('applyBeforeFailing')]
    public function test_an_ambiguous_timeout_is_recorded_unknown_and_never_replayed(bool $applied): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, $applied);

        $outcome = $this->act($t, 'pauseCampaign', $campaign->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::AwaitingConfirmation, $outcome->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_AWAITING, $outcome->messageKey);
        $this->assertSame('timeout', $outcome->failureClassification);
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
        // Local state is untouched whether or not Meta applied it.
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
        $this->assertSame($applied ? 'PAUSED' : 'ACTIVE', $this->fakeMeta->campaignStatus(self::AD_ACCOUNT, MetaPhotoBoothFixture::CAMPAIGN_LEADS));

        $operation = $this->lastOperation(MetaOperationType::CampaignStatusChanged);
        $this->assertSame(MetaOperationStatus::Unknown, $operation->status);
        $this->assertSame('timeout', $operation->failure_classification);

        // The user tries again (pause, or the opposite): answered from the ledger, not re-sent.
        $retry = $this->act($t, 'pauseCampaign', $campaign->uid);
        $opposite = $this->act($t, 'resumeCampaign', $campaign->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::AwaitingConfirmation, $retry->status);
        $this->assertSame($operation->uid, $retry->operationUid);
        $this->assertSame(MetaAdsMutationOutcomeStatus::AwaitingConfirmation, $opposite->status);
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame(1, $this->mutationOperations());
    }

    public function test_an_ambiguous_ad_set_and_ad_are_unknown_too(): void
    {
        $t = $this->mutationTenant();
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 2, true);

        $adSet = $this->act($t, 'pauseAdSet', $this->adSetOf($t['account'])->uid);
        $ad = $this->act($t, 'pauseAd', $this->adOf($t['account'])->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::AwaitingConfirmation, $adSet->status);
        $this->assertSame(MetaAdsMutationOutcomeStatus::AwaitingConfirmation, $ad->status);
        $this->assertSame(MetaOperationStatus::Unknown, $this->lastOperation(MetaOperationType::AdSetStatusChanged)->status);
        $this->assertSame(MetaOperationStatus::Unknown, $this->lastOperation(MetaOperationType::AdStatusChanged)->status);
    }

    public function test_an_applied_then_failed_mutation_is_confirmed_by_the_next_sync_and_never_re_sent(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::timeout(true), 1, true);

        $this->act($t, 'pauseCampaign', $campaign->uid);
        $this->syncFromFakeProvider($t['account']);
        app(\App\Library\MetaAds\Mutations\MetaAdsMutationReconciler::class)->reconcile($t['account'], 'campaigns', false);

        $this->assertSame(MetaOperationStatus::Succeeded, $this->lastOperation(MetaOperationType::CampaignStatusChanged)->status);
        $this->assertSame('PAUSED', $campaign->fresh()->status);

        $again = $this->act($t, 'pauseCampaign', $campaign->uid);
        $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $again->status);
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
    }

    public function test_a_rate_limit_is_deferred_not_failed_and_not_retried_and_does_not_block_a_later_attempt(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::rateLimited(17));

        $outcome = $this->act($t, 'pauseCampaign', $campaign->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Deferred, $outcome->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_DEFERRED, $outcome->messageKey);
        $this->assertSame('rate_limited', $outcome->failureClassification);
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
        $this->assertSame(MetaOperationStatus::Deferred, $this->lastOperation(MetaOperationType::CampaignStatusChanged)->status);

        $later = $this->act($t, 'pauseCampaign', $campaign->uid);
        $this->assertSame(MetaAdsMutationOutcomeStatus::Succeeded, $later->status);
        $this->assertSame(2, $this->fakeMeta->callCount('setStatus'));
    }

    /** @return array<string, array{0: MetaProviderException, 1: string}> */
    public static function definiteRejections(): array
    {
        return [
            'validation' => [MetaProviderException::validation(100), 'validation'],
            'access denied' => [MetaProviderException::accessDenied(200), 'access_denied'],
            'not found' => [MetaProviderException::notFound(803), 'not_found'],
        ];
    }

    #[DataProvider('definiteRejections')]
    public function test_a_definite_rejection_is_failed_with_its_classification_and_leaves_local_state(MetaProviderException $exception, string $classification): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $adSet = $this->adSetOf($t['account']);
        $ad = $this->adOf($t['account']);
        $this->fakeMeta->failNext('setStatus', $exception, 3);

        $outcomes = [
            $this->act($t, 'pauseCampaign', $campaign->uid),
            $this->act($t, 'pauseAdSet', $adSet->uid),
            $this->act($t, 'pauseAd', $ad->uid),
        ];

        foreach ($outcomes as $outcome) {
            $this->assertSame(MetaAdsMutationOutcomeStatus::Failed, $outcome->status);
            $this->assertSame($classification, $outcome->failureClassification);
            $this->assertSame(MetaAdsMutationOutcome::KEY_FAILED, $outcome->messageKey);
        }

        $this->assertSame('ACTIVE', $campaign->fresh()->status);
        $this->assertSame('ACTIVE', $adSet->fresh()->status);
        $this->assertSame('ACTIVE', $ad->fresh()->status);

        $operations = BusinessMetaOperation::query()->whereIn('operation_type', [
            MetaOperationType::CampaignStatusChanged->value, MetaOperationType::AdSetStatusChanged->value, MetaOperationType::AdStatusChanged->value,
        ])->get();
        $this->assertCount(3, $operations);
        foreach ($operations as $operation) {
            $this->assertSame(MetaOperationStatus::Failed, $operation->status);
            $this->assertSame($classification, $operation->failure_classification);
        }
    }

    public function test_our_own_hourly_budget_refusing_is_deferred_with_no_provider_call(): void
    {
        $t = $this->mutationTenant();
        config(['meta_ads.sync.max_calls_per_business_per_hour' => 1]);

        // One request already made this hour uses the whole budget.
        $used = app(MetaAdsOperationLedger::class)->open((int) $t['business']->id, MetaOperationType::MetaAdsSync, null, 'used');
        $used->forceFill(['provider_call_count' => 1])->save();

        $campaign = $this->campaignOf($t['account']);
        $outcome = $this->act($t, 'pauseCampaign', $campaign->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Deferred, $outcome->status);
        $this->assertSame('budget_exhausted', $outcome->failureClassification);
        // The Fake records the attempt before reserving; the reservation refused, so nothing was applied.
        $this->assertSame('ACTIVE', $this->fakeMeta->campaignStatus(self::AD_ACCOUNT, MetaPhotoBoothFixture::CAMPAIGN_LEADS));
        $this->assertSame('ACTIVE', $campaign->fresh()->status);
    }
    public function test_a_token_rejected_by_meta_marks_the_connection_expired_and_asks_to_reconnect(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeMeta->failNext('setStatus', MetaProviderException::tokenExpired());

        $outcome = $this->act($t, 'pauseCampaign', $campaign->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Failed, $outcome->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_RECONNECT, $outcome->messageKey);
        $this->assertSame('token_expired', $outcome->failureClassification);
        $this->assertStringContainsString('Reconnect', $outcome->message);
        $this->assertSame('ACTIVE', $campaign->fresh()->status);

        $connection = $t['connection']->fresh();
        $this->assertSame(MetaConnectionState::Expired, $connection->state);
        $this->assertNull($connection->access_token_encrypted);
        $this->assertSame(MetaOperationStatus::Failed, $this->lastOperation(MetaOperationType::CampaignStatusChanged)->status);

        // The next attempt is refused as a dead connection, not sent.
        $this->expectException(\App\Exceptions\MetaAds\MetaAdsMutationConnectionException::class);
        $this->act($t, 'pauseCampaign', $campaign->uid);
    }

    public function test_a_revoked_app_marks_the_connection_revoked(): void
    {
        $t = $this->mutationTenant();
        $this->fakeMeta->failNext('setStatus', MetaProviderException::invalidToken(458));

        $outcome = $this->act($t, 'pauseCampaign', $this->campaignOf($t['account'])->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Failed, $outcome->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_RECONNECT, $outcome->messageKey);
        $this->assertSame(MetaConnectionState::Revoked, $t['connection']->fresh()->state);
    }

    public function test_a_token_past_its_expiry_fails_the_operation_without_a_mutate(): void
    {
        $t = $this->mutationTenant();
        $t['connection']->forceFill(['token_expires_at' => now()->subMinute()])->save();

        $outcome = $this->act($t, 'pauseCampaign', $this->campaignOf($t['account'])->uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Failed, $outcome->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_RECONNECT, $outcome->messageKey);
        $this->assertSame('token_expired', $outcome->failureClassification);
        $this->assertSame(0, $this->fakeMeta->callCount('setStatus'));
        $this->assertSame(MetaConnectionState::Expired, $t['connection']->fresh()->state);
    }

    // ---------------------------------------------------------------
    // Transaction / locking discipline
    // ---------------------------------------------------------------

    public function test_no_transaction_is_opened_by_the_service_during_the_provider_call(): void
    {
        $t = $this->mutationTenant();
        $baseline = DB::transactionLevel(); // RefreshDatabase's own wrapper.
        $levels = [];
        $inner = $this->fakeMeta;

        $this->app->instance(MetaMutationClient::class, new class($inner, $levels) implements MetaMutationClient {
            public function __construct(private $inner, public array &$levels)
            {
            }

            public function setStatus(string $accessToken, ?string $adAccountId, string $externalId, string $targetType, string $requestedState): MetaMutationResult
            {
                $this->levels[] = DB::transactionLevel();

                return $this->inner->setStatus($accessToken, $adAccountId, $externalId, $targetType, $requestedState);
            }
        });

        $service = $this->app->make(MetaAdsMutationService::class);
        $actor = (int) $t['actor']->id;

        $service->pauseCampaign($t['business'], $t['workspace'], $actor, $this->campaignOf($t['account'])->uid);
        $service->pauseAdSet($t['business'], $t['workspace'], $actor, $this->adSetOf($t['account'])->uid);
        $service->pauseAd($t['business'], $t['workspace'], $actor, $this->adOf($t['account'])->uid);

        $this->assertSame([$baseline, $baseline, $baseline], $levels);
    }

    public function test_the_account_row_is_locked_and_sequential_double_submits_yield_one_provider_call(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower($query->sql);
        });

        $this->act($t, 'pauseCampaign', $campaign->uid);
        $this->act($t, 'pauseCampaign', $campaign->uid);

        $locks = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'meta_ads_accounts') && str_contains($sql, 'for update'));
        $this->assertCount(2, $locks, 'every mutation request takes the account row lock');
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
    }

    public function test_a_second_request_arriving_while_the_first_is_in_flight_does_not_send(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $service = $this->service();
        $secondOutcome = null;
        $uid = $campaign->uid;

        $this->app->instance(MetaMutationClient::class, new class($this->fakeMeta, function () use (&$secondOutcome, $service, $t, $uid): void {
            $secondOutcome = $service->pauseCampaign($t['business'], $t['workspace'], (int) $t['actor']->id, $uid);
        }) implements MetaMutationClient {
            public function __construct(private $inner, private $during)
            {
            }

            public function setStatus(string $accessToken, ?string $adAccountId, string $externalId, string $targetType, string $requestedState): MetaMutationResult
            {
                ($this->during)();

                return $this->inner->setStatus($accessToken, $adAccountId, $externalId, $targetType, $requestedState);
            }
        });

        $first = $this->app->make(MetaAdsMutationService::class)->pauseCampaign($t['business'], $t['workspace'], (int) $t['actor']->id, $uid);

        $this->assertSame(MetaAdsMutationOutcomeStatus::Succeeded, $first->status);
        $this->assertNotNull($secondOutcome);
        $this->assertSame(MetaAdsMutationOutcomeStatus::DuplicateNoop, $secondOutcome->status);
        $this->assertSame(MetaAdsMutationOutcome::KEY_IN_PROGRESS, $secondOutcome->messageKey);
        $this->assertSame(1, $this->fakeMeta->callCount('setStatus'));
    }

    // ---------------------------------------------------------------
    // Safety
    // ---------------------------------------------------------------

    public function test_the_service_only_ever_writes_paused_or_active(): void
    {
        $t = $this->mutationTenant();

        foreach ([['Campaign', $this->campaignOf($t['account'])], ['AdSet', $this->adSetOf($t['account'])], ['Ad', $this->adOf($t['account'])]] as [$suffix, $row]) {
            $this->act($t, 'pause' . $suffix, $row->uid);
            $this->act($t, 'resume' . $suffix, $row->uid);
        }

        $sent = array_column(array_column($this->fakeMeta->callsTo('setStatus'), 'args'), 'requested_state');

        $this->assertCount(6, $sent);
        $this->assertEqualsCanonicalizing(['PAUSED', 'ACTIVE', 'PAUSED', 'ACTIVE', 'PAUSED', 'ACTIVE'], $sent);
    }

    public function test_every_outcome_key_has_fixed_copy_that_never_contains_provider_text(): void
    {
        $messages = MetaAdsMutationOutcome::flashMessages();

        $this->assertSame([
            'meta_ads.mutation.succeeded', 'meta_ads.mutation.awaiting_confirmation', 'meta_ads.mutation.failed',
            'meta_ads.mutation.reconnect_required', 'meta_ads.mutation.deferred', 'meta_ads.mutation.already_in_progress',
            'meta_ads.mutation.already_in_state',
        ], array_keys($messages));
        $this->assertCount(7, array_unique($messages));
    }
}
