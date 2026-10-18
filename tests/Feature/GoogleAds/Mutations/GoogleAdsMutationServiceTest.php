<?php

namespace Tests\Feature\GoogleAds\Mutations;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\DTO\GoogleAds\GoogleAdsMutationResult;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsMutationKind;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\GoogleAds\Contracts\GoogleAdsMutationClient;
use App\Library\GoogleAds\HttpGoogleAdsMutationClient;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationOperations;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationService;
use App\Library\GoogleAds\Mutations\GoogleAdsMutationValidationException;
use App\Library\GoogleAds\Mutations\MutationOutcome;
use App\Library\GoogleAds\Mutations\MutationOutcomeStatus;
use App\Library\GoogleAds\PhotoBoothFixture;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsMutation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\GoogleAds\Mutations\Concerns\CreatesMutationFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 contract §6 — the five mutations, their ledger/detail
 * rows, local state, duplicate suppression and every provider outcome. The
 * FAKE provider only: no real request is ever made.
 */
class GoogleAdsMutationServiceTest extends TestCase
{
    use CreatesMutationFixtures;
    use RefreshDatabase;

    private const CUSTOMER = PhotoBoothFixture::CUSTOMER_ID;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindFakeAds();
    }

    private function service(): GoogleAdsMutationService
    {
        return app(GoogleAdsMutationService::class);
    }

    private function lastOperation(GoogleOperationType $type): BusinessGoogleOperation
    {
        return BusinessGoogleOperation::query()->where('operation_type', $type->value)->latest('id')->firstOrFail();
    }

    private function mutationOperations(): int
    {
        return BusinessGoogleOperation::query()->whereIn('operation_type', GoogleAdsMutationOperations::typeValues())->count();
    }

    // ---------------------------------------------------------------
    // Success paths
    // ---------------------------------------------------------------

    public function test_pause_campaign_calls_the_provider_once_and_updates_ledger_detail_and_local_state(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $outcome = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        $this->assertSame(MutationOutcomeStatus::Succeeded, $outcome->status);
        $this->assertSame(MutationOutcome::KEY_SUCCEEDED, $outcome->messageKey);

        $calls = $this->fakeAds->callsTo('setCampaignStatus');
        $this->assertCount(1, $calls);
        // The external id came from OUR row, derived server-side.
        $this->assertSame(PhotoBoothFixture::CAMPAIGN_RENTAL, $calls[0]['args']['campaign_id']);
        $this->assertSame('PAUSED', $calls[0]['args']['status']);
        $this->assertSame(self::CUSTOMER, $calls[0]['customer_id']);
        $this->assertSame(PhotoBoothFixture::MANAGER_ID, $calls[0]['login_customer_id']);

        $this->assertSame(GoogleAdsEntityStatus::Paused, $campaign->fresh()->status);
        $this->assertSame(GoogleAdsEntityStatus::Paused, $this->fakeAds->campaignStatus(self::CUSTOMER, PhotoBoothFixture::CAMPAIGN_RENTAL));

        $operation = $this->lastOperation(GoogleOperationType::AdsCampaignStatusChanged);
        $this->assertSame($outcome->operationUid, $operation->uid);
        $this->assertSame(GoogleOperationStatus::Succeeded, $operation->status);
        $this->assertSame($t['actor']->id, (int) $operation->actor_user_id);
        $this->assertSame((int) $t['business']->id, (int) $operation->business_id);
        $this->assertNotNull($operation->completed_at);
        $this->assertStringContainsString('Pause campaign', (string) $operation->summary);
        // A status change re-addresses the same resource every time: no (unique) reference.
        $this->assertNull($operation->provider_operation_reference);
        // One token exchange + one mutate, both through the call budget.
        $this->assertSame(2, (int) $operation->provider_call_count);

        $detail = GoogleAdsMutation::query()->where('business_google_operation_id', $operation->id)->sole();
        $this->assertSame(GoogleAdsMutationKind::CampaignStatus, $detail->kind);
        $this->assertSame('campaign', $detail->target_type);
        $this->assertSame((int) $campaign->id, (int) $detail->target_local_id);
        $this->assertSame('PAUSED', $detail->requested_state);
        $this->assertNull($detail->params);
        $this->assertSame($t['actor']->id, (int) $detail->actor_user_id);
        $this->assertSame(64, strlen($detail->dedupe_key));
        $this->assertSame((int) $t['account']->id, (int) $detail->google_ads_account_id);
    }

    public function test_resume_campaign_after_a_pause(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $outcome = $this->service()->resumeCampaign($t['business'], $t['actor'], $campaign->uid);

        $this->assertSame(MutationOutcomeStatus::Succeeded, $outcome->status);
        $this->assertSame(2, $this->fakeAds->callCount('setCampaignStatus'));
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $campaign->fresh()->status);
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $this->fakeAds->campaignStatus(self::CUSTOMER, PhotoBoothFixture::CAMPAIGN_RENTAL));
    }

    public function test_pause_and_resume_keyword(): void
    {
        $t = $this->mutationTenant();
        $keyword = $this->positiveKeywordOf($t['account']);

        $pause = $this->service()->pauseKeyword($t['business'], $t['actor'], $keyword->uid);

        $this->assertSame(MutationOutcomeStatus::Succeeded, $pause->status);
        $calls = $this->fakeAds->callsTo('setKeywordStatus');
        $this->assertCount(1, $calls);
        [$adGroup, $criterion] = explode('~', $keyword->external_criterion_id);
        $this->assertSame($adGroup, $calls[0]['args']['ad_group_id']);
        $this->assertSame($criterion, $calls[0]['args']['criterion_id']);
        $this->assertSame('PAUSED', $calls[0]['args']['status']);
        $this->assertSame(GoogleAdsEntityStatus::Paused, $keyword->fresh()->status);

        $operation = $this->lastOperation(GoogleOperationType::AdsKeywordStatusChanged);
        $this->assertSame(GoogleOperationStatus::Succeeded, $operation->status);
        $this->assertSame($t['actor']->id, (int) $operation->actor_user_id);
        $detail = GoogleAdsMutation::query()->where('business_google_operation_id', $operation->id)->sole();
        $this->assertSame(GoogleAdsMutationKind::KeywordStatus, $detail->kind);
        $this->assertSame('keyword', $detail->target_type);
        $this->assertSame('PAUSED', $detail->requested_state);

        $resume = $this->service()->resumeKeyword($t['business'], $t['actor'], $keyword->uid);

        $this->assertSame(MutationOutcomeStatus::Succeeded, $resume->status);
        $this->assertSame(2, $this->fakeAds->callCount('setKeywordStatus'));
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $keyword->fresh()->status);
    }

    public function test_campaign_scope_negative_uses_the_campaign_parent_and_defaults_to_exact(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $outcome = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, '  cheap   photo booth ');

        $this->assertSame(MutationOutcomeStatus::Succeeded, $outcome->status);

        $calls = $this->fakeAds->callsTo('addNegativeKeyword');
        $this->assertCount(1, $calls);
        $this->assertSame('campaign', $calls[0]['args']['scope']);
        $this->assertSame(PhotoBoothFixture::CAMPAIGN_RENTAL, $calls[0]['args']['parent_id']);
        $this->assertSame('EXACT', $calls[0]['args']['match_type']);
        $this->assertSame('cheap photo booth', $calls[0]['args']['text']);

        $local = $this->negativeOf($t['account'], 'cheap photo booth');
        $this->assertNotNull($local);
        $this->assertTrue($local->is_negative);
        $this->assertSame(GoogleAdsKeywordLevel::Campaign, $local->level);
        $this->assertSame(GoogleAdsMatchType::Exact, $local->match_type);
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $local->status);
        $this->assertSame((int) $campaign->id, (int) $local->google_ads_campaign_id);
        $this->assertNull($local->google_ads_ad_group_id);
        $this->assertMatchesRegularExpression('/^' . PhotoBoothFixture::CAMPAIGN_RENTAL . '~\d+$/', $local->external_criterion_id);

        $operation = $this->lastOperation(GoogleOperationType::AdsNegativeKeywordAdded);
        $this->assertSame(GoogleOperationStatus::Succeeded, $operation->status);
        $this->assertSame($t['actor']->id, (int) $operation->actor_user_id);
        $this->assertStringContainsString('cheap photo booth', (string) $operation->summary);
        $this->assertSame('customers/' . self::CUSTOMER . '/campaignCriteria/' . $local->external_criterion_id, $operation->provider_operation_reference);

        $detail = GoogleAdsMutation::query()->where('business_google_operation_id', $operation->id)->sole();
        $this->assertSame(GoogleAdsMutationKind::NegativeKeyword, $detail->kind);
        $this->assertNull($detail->requested_state);
        $this->assertSame('cheap photo booth', $detail->params['text']);
        $this->assertSame('EXACT', $detail->params['match_type']);
        $this->assertSame('campaign', $detail->params['scope']);
    }

    public function test_ad_group_scope_negative_uses_the_ad_group_parent_from_the_search_term(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $adGroup = $this->adGroupOf($t['account']);
        $term = $this->searchTermOf($t['account']);

        $outcome = $this->service()->addNegativeKeyword(
            $t['business'], $t['actor'], $campaign->uid, 'cheap photo booth',
            GoogleAdsKeywordLevel::AdGroup, GoogleAdsMatchType::Phrase, $term->id,
        );

        $this->assertSame(MutationOutcomeStatus::Succeeded, $outcome->status);

        $calls = $this->fakeAds->callsTo('addNegativeKeyword');
        $this->assertCount(1, $calls);
        $this->assertSame('ad_group', $calls[0]['args']['scope']);
        $this->assertSame($adGroup->external_ad_group_id, $calls[0]['args']['parent_id']);
        $this->assertSame('PHRASE', $calls[0]['args']['match_type']);

        $local = $this->negativeOf($t['account'], 'cheap photo booth', GoogleAdsKeywordLevel::AdGroup);
        $this->assertNotNull($local);
        $this->assertSame((int) $adGroup->id, (int) $local->google_ads_ad_group_id);
        $this->assertSame((int) $campaign->id, (int) $local->google_ads_campaign_id);
    }

    public function test_ad_group_scope_without_a_search_term_is_a_validation_refusal_with_no_call_and_no_ledger_row(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        try {
            $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'cheap', GoogleAdsKeywordLevel::AdGroup);
            $this->fail('expected a validation refusal');
        } catch (GoogleAdsMutationValidationException $e) {
            $this->assertSame(422, $e->httpStatus());
            $this->assertSame('ad_group_scope_requires_search_term', $e->reason);
        }

        $this->assertSame(0, $this->fakeAds->callCount('addNegativeKeyword'));
        $this->assertSame(0, $this->mutationOperations());
    }

    #[DataProvider('invalidNegativeText')]
    public function test_invalid_negative_text_is_refused_locally(string $text): void
    {
        $t = $this->mutationTenant();

        $this->expectException(GoogleAdsMutationValidationException::class);

        try {
            $this->service()->addNegativeKeyword($t['business'], $t['actor'], $this->campaignOf($t['account'])->uid, $text);
        } finally {
            $this->assertSame(0, $this->fakeAds->callCount('addNegativeKeyword'));
            $this->assertSame(0, $this->mutationOperations());
        }
    }

    /** @return array<string, array{0: string}> */
    public static function invalidNegativeText(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'too long' => [str_repeat('a', 81)],
            'too many words' => ['one two three four five six seven eight nine ten eleven'],
        ];
    }

    // ---------------------------------------------------------------
    // Duplicates / retries
    // ---------------------------------------------------------------

    public function test_a_double_submitted_pause_sends_one_provider_call(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $first = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $second = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        $this->assertSame(MutationOutcomeStatus::Succeeded, $first->status);
        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $second->status);
        $this->assertSame(MutationOutcome::KEY_ALREADY_IN_STATE, $second->messageKey);
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'));
        $this->assertSame(1, $this->mutationOperations());
    }

    public function test_resuming_an_enabled_campaign_is_a_noop_without_a_call(): void
    {
        $t = $this->mutationTenant();

        $outcome = $this->service()->resumeCampaign($t['business'], $t['actor'], $this->campaignOf($t['account'])->uid);

        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $outcome->status);
        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));
        $this->assertSame(0, $this->mutationOperations());
    }

    public function test_a_double_submitted_negative_sends_one_call_and_matching_is_case_insensitive(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $first = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'Cheap Photo Booth');
        $second = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'cheap photo booth');
        $third = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'CHEAP PHOTO BOOTH');

        $this->assertSame(MutationOutcomeStatus::Succeeded, $first->status);
        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $second->status);
        $this->assertSame(MutationOutcome::KEY_ALREADY_EXCLUDED, $second->messageKey);
        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $third->status);
        $this->assertSame(1, $this->fakeAds->callCount('addNegativeKeyword'));
        $this->assertSame(1, GoogleAdsKeyword::query()->where('is_negative', true)->whereRaw('LOWER(text) = ?', ['cheap photo booth'])->count());
    }

    public function test_an_already_synced_negative_is_detected_case_insensitively_with_no_call(): void
    {
        $t = $this->mutationTenant();
        // The fixture already has a campaign-level PHRASE negative "diy photo booth".
        $campaign = $this->campaignOf($t['account']);

        $outcome = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'DIY Photo Booth', GoogleAdsKeywordLevel::Campaign, GoogleAdsMatchType::Phrase);

        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $outcome->status);
        $this->assertSame(0, $this->fakeAds->callCount('addNegativeKeyword'));
        $this->assertSame(0, $this->mutationOperations());

        // A different match type is a different negative.
        $other = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'DIY Photo Booth', GoogleAdsKeywordLevel::Campaign, GoogleAdsMatchType::Exact);
        $this->assertSame(MutationOutcomeStatus::Succeeded, $other->status);
    }

    public function test_a_previously_succeeded_negative_is_never_re_sent_even_if_the_local_row_is_gone(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'wedding dj');
        GoogleAdsKeyword::query()->where('is_negative', true)->whereRaw('LOWER(text) = ?', ['wedding dj'])->delete();

        $again = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'wedding dj');

        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $again->status);
        $this->assertSame(1, $this->fakeAds->callCount('addNegativeKeyword'));
    }

    public function test_a_pending_operation_for_the_same_target_is_returned_not_re_sent(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->seedLedgerOperation($t, $campaign->id, GoogleOperationStatus::Pending, 'PAUSED');

        $outcome = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $outcome->status);
        $this->assertSame(MutationOutcome::KEY_IN_PROGRESS, $outcome->messageKey);
        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));

        // The opposite state is blocked too until the in-flight change settles.
        $resume = $this->service()->resumeCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $resume->status);
        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));
    }

    /**
     * @param  array<string, mixed>  $t
     */
    private function seedLedgerOperation(array $t, int $campaignId, GoogleOperationStatus $status, string $requested): BusinessGoogleOperation
    {
        $ledger = app(\App\Library\GoogleAds\GoogleAdsOperationLedger::class);
        $operation = $ledger->open((int) $t['business']->id, GoogleOperationType::AdsCampaignStatusChanged, (int) $t['actor']->id, 'seed');
        $operation->forceFill(['status' => $status])->save();

        GoogleAdsMutation::create([
            'business_id' => $t['account']->business_id, 'google_ads_account_id' => $t['account']->id,
            'business_google_operation_id' => $operation->id, 'kind' => 'campaign_status', 'target_type' => 'campaign',
            'target_local_id' => $campaignId, 'target_resource_name' => 'customers/x/campaigns/y',
            'requested_state' => $requested, 'dedupe_key' => hash('sha256', uniqid('', true)), 'actor_user_id' => $t['actor']->id,
        ]);

        return $operation;
    }

    public function test_a_removed_campaign_or_keyword_is_refused_without_a_call(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $keyword = $this->positiveKeywordOf($t['account']);
        $campaign->forceFill(['status' => 'REMOVED'])->save();
        $keyword->forceFill(['status' => 'REMOVED'])->save();

        foreach ([
            fn () => $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid),
            fn () => $this->service()->resumeCampaign($t['business'], $t['actor'], $campaign->uid),
            fn () => $this->service()->pauseKeyword($t['business'], $t['actor'], $keyword->uid),
            fn () => $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'anything'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('expected a refusal');
            } catch (GoogleAdsMutationValidationException $e) {
                $this->assertSame('entity_not_changeable', $e->reason);
            }
        }

        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus') + $this->fakeAds->callCount('setKeywordStatus') + $this->fakeAds->callCount('addNegativeKeyword'));
        $this->assertSame(0, $this->mutationOperations());
    }

    public function test_a_negative_keyword_cannot_be_paused(): void
    {
        $t = $this->mutationTenant();
        $negative = $this->negativeOf($t['account'], 'free');

        $this->expectException(GoogleAdsMutationValidationException::class);

        try {
            $this->service()->pauseKeyword($t['business'], $t['actor'], $negative->uid);
        } finally {
            $this->assertSame(0, $this->fakeAds->callCount('setKeywordStatus'));
        }
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
        $this->fakeAds->failNext('setCampaignStatus', GoogleAdsProviderException::timeout(true), 1, $applied);

        $outcome = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $outcome->status);
        $this->assertSame(MutationOutcome::KEY_AWAITING, $outcome->messageKey);
        $this->assertSame('timeout', $outcome->failureClassification);
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'));
        // Local state is untouched whether or not Google applied it.
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $campaign->fresh()->status);
        $this->assertSame(
            $applied ? GoogleAdsEntityStatus::Paused : GoogleAdsEntityStatus::Enabled,
            $this->fakeAds->campaignStatus(self::CUSTOMER, PhotoBoothFixture::CAMPAIGN_RENTAL),
        );

        $operation = $this->lastOperation(GoogleOperationType::AdsCampaignStatusChanged);
        $this->assertSame(GoogleOperationStatus::Unknown, $operation->status);
        $this->assertSame('timeout', $operation->failure_classification);

        // The user tries again (pause, or the opposite): answered from the ledger, not re-sent.
        $retry = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $opposite = $this->service()->resumeCampaign($t['business'], $t['actor'], $campaign->uid);

        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $retry->status);
        $this->assertSame($operation->uid, $retry->operationUid);
        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $opposite->status);
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'));
        $this->assertSame(1, $this->mutationOperations());
    }

    public function test_an_ambiguous_negative_is_not_re_sent_by_a_second_attempt(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeAds->failNext('addNegativeKeyword', GoogleAdsProviderException::timeout(true), 1, true);

        $first = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'wedding dj');
        $second = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'Wedding DJ');

        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $first->status);
        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $second->status);
        $this->assertSame(1, $this->fakeAds->callCount('addNegativeKeyword'));
        $this->assertNull($this->negativeOf($t['account'], 'wedding dj'));
    }

    public function test_a_succeeded_negative_blocks_a_double_submit_but_not_a_re_add_after_a_complete_sync_removed_it(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $first = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'wedding dj');
        $this->assertSame(MutationOutcomeStatus::Succeeded, $first->status);

        // We hold no local row for it (never stored, or removed by a sync): only the ledger knows.
        GoogleAdsKeyword::query()->where('google_ads_account_id', $t['account']->id)->where('is_negative', true)->whereRaw('LOWER(text) = ?', ['wedding dj'])->delete();

        $double = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'wedding dj');
        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $double->status, 'before any sync the ledger still protects against a double-submit');
        $this->assertSame(1, $this->fakeAds->callCount('addNegativeKeyword'));

        // A PARTIAL run is not a complete read of the keywords: still protected.
        $this->syncRun($t['account'], 'partial');
        $partial = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'wedding dj');
        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $partial->status);

        // A complete sync after the operation did not find it: it was removed upstream, so re-adding is allowed.
        $this->syncRun($t['account'], 'succeeded');
        $again = $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'wedding dj');
        // (The fake provider still holds the negative and rejects the duplicate; what matters is that it was SENT.)
        $this->assertNotSame(MutationOutcomeStatus::DuplicateNoop, $again->status);
        $this->assertSame(2, $this->fakeAds->callCount('addNegativeKeyword'));
    }

    private function syncRun(\App\Models\GoogleAdsAccount $account, string $state): void
    {
        DB::table('google_ads_sync_runs')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(), 'business_id' => $account->business_id,
            'google_ads_account_id' => $account->id, 'state' => $state, 'trigger' => 'scheduled',
            'started_at' => now()->addMinutes(2), 'completed_at' => now()->addMinutes(3),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_a_503_answer_to_a_real_mutate_is_unknown_and_the_second_attempt_is_not_re_sent(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        // The REAL mutation client over a faked wire (tokens still come from the fake auth client).
        $this->app->bind(GoogleAdsMutationClient::class, HttpGoogleAdsMutationClient::class);
        Http::fake(['googleads.googleapis.com/*' => Http::response([], 503)]);

        $first = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $second = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $first->status);
        $this->assertSame(MutationOutcomeStatus::AwaitingConfirmation, $second->status);
        $this->assertSame(GoogleOperationStatus::Unknown, $this->lastOperation(GoogleOperationType::AdsCampaignStatusChanged)->status);
        Http::assertSentCount(1);
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $campaign->fresh()->status);
    }
    public function test_a_rate_limit_is_deferred_not_failed_and_not_retried_and_does_not_block_a_later_attempt(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $this->fakeAds->failNext('setCampaignStatus', GoogleAdsProviderException::rateLimited());

        $outcome = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        $this->assertSame(MutationOutcomeStatus::Deferred, $outcome->status);
        $this->assertSame('rate_limited', $outcome->failureClassification);
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'));
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $campaign->fresh()->status);

        $operation = $this->lastOperation(GoogleOperationType::AdsCampaignStatusChanged);
        $this->assertSame(GoogleOperationStatus::Deferred, $operation->status);

        $later = $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->assertSame(MutationOutcomeStatus::Succeeded, $later->status);
        $this->assertSame(2, $this->fakeAds->callCount('setCampaignStatus'));
    }

    /** @return array<string, array{0: GoogleAdsProviderException, 1: string}> */
    public static function definiteRejections(): array
    {
        return [
            'validation' => [GoogleAdsProviderException::validation(), 'validation'],
            'access denied' => [GoogleAdsProviderException::accessDenied(), 'access_denied'],
            'not found' => [GoogleAdsProviderException::notFound(), 'not_found'],
        ];
    }

    #[DataProvider('definiteRejections')]
    public function test_a_definite_rejection_is_failed_with_its_classification_and_leaves_local_state(GoogleAdsProviderException $exception, string $classification): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $keyword = $this->positiveKeywordOf($t['account']);

        $this->fakeAds->failNext('setCampaignStatus', $exception);
        $this->fakeAds->failNext('setKeywordStatus', $exception);
        $this->fakeAds->failNext('addNegativeKeyword', $exception);

        $outcomes = [
            $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid),
            $this->service()->pauseKeyword($t['business'], $t['actor'], $keyword->uid),
            $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'rejected term'),
        ];

        foreach ($outcomes as $outcome) {
            $this->assertSame(MutationOutcomeStatus::Failed, $outcome->status);
            $this->assertSame($classification, $outcome->failureClassification);
            $this->assertSame(MutationOutcome::KEY_FAILED, $outcome->messageKey);
        }

        $this->assertSame(GoogleAdsEntityStatus::Enabled, $campaign->fresh()->status);
        $this->assertSame(GoogleAdsEntityStatus::Enabled, $keyword->fresh()->status);
        $this->assertNull($this->negativeOf($t['account'], 'rejected term'));

        $operations = BusinessGoogleOperation::query()->whereIn('operation_type', GoogleAdsMutationOperations::typeValues())->get();
        $this->assertCount(3, $operations);
        foreach ($operations as $operation) {
            $this->assertSame(GoogleOperationStatus::Failed, $operation->status);
            $this->assertSame($classification, $operation->failure_classification);
        }
    }

    public function test_a_revoked_grant_during_token_exchange_fails_the_operation_without_a_mutate(): void
    {
        $t = $this->mutationTenant();
        $this->fakeAds->failNext('exchangeRefreshToken', GoogleAdsProviderException::invalidGrant());

        $outcome = $this->service()->pauseCampaign($t['business'], $t['actor'], $this->campaignOf($t['account'])->uid);

        $this->assertSame(MutationOutcomeStatus::Failed, $outcome->status);
        $this->assertSame('invalid_grant', $outcome->failureClassification);
        $this->assertSame(0, $this->fakeAds->callCount('setCampaignStatus'));
    }

    // ---------------------------------------------------------------
    // Preview
    // ---------------------------------------------------------------

    public function test_the_preview_returns_the_exact_confirmation_facts_with_no_provider_call_or_write(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $term = $this->searchTermOf($t['account']);
        $adGroup = $this->adGroupOf($t['account']);

        $campaignPreview = $this->service()->previewNegativeKeyword($t['business'], $t['actor'], $campaign->uid, ' Cheap  Booth ');
        $adGroupPreview = $this->service()->previewNegativeKeyword(
            $t['business'], $t['actor'], $campaign->uid, 'cheap booth', GoogleAdsKeywordLevel::AdGroup, GoogleAdsMatchType::Phrase, $term->id,
        );
        $existing = $this->service()->previewNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'DIY photo booth', GoogleAdsKeywordLevel::Campaign, GoogleAdsMatchType::Phrase);

        $this->assertSame([
            'term' => 'Cheap Booth', 'scope' => 'campaign', 'scope_label' => 'Campaign',
            'parent_name' => $campaign->name, 'match_type' => 'EXACT', 'already_excluded' => false,
        ], $campaignPreview->toArray());
        $this->assertSame('Ad group', $adGroupPreview->scopeLabel);
        $this->assertSame($adGroup->name, $adGroupPreview->parentName);
        $this->assertSame('PHRASE', $adGroupPreview->matchType->value);
        $this->assertTrue($existing->alreadyExcluded);

        $this->assertSame(0, $this->fakeAds->callCount());
        $this->assertSame(0, $this->mutationOperations());
        $this->assertSame(0, GoogleAdsMutation::query()->count());
    }

    // ---------------------------------------------------------------
    // Transaction / locking discipline
    // ---------------------------------------------------------------

    public function test_no_transaction_is_opened_by_the_service_during_the_provider_call(): void
    {
        $t = $this->mutationTenant();
        $baseline = DB::transactionLevel(); // RefreshDatabase's own wrapper.
        $levels = [];
        $inner = $this->fakeAds;

        $this->app->instance(GoogleAdsMutationClient::class, new class($inner, $levels) implements GoogleAdsMutationClient {
            public function __construct(private $inner, public array &$levels)
            {
            }

            public function setCampaignStatus(GoogleAdsAccessContext $context, string $campaignId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult
            {
                $this->levels[] = DB::transactionLevel();

                return $this->inner->setCampaignStatus($context, $campaignId, $status);
            }

            public function setKeywordStatus(GoogleAdsAccessContext $context, string $adGroupId, string $criterionId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult
            {
                $this->levels[] = DB::transactionLevel();

                return $this->inner->setKeywordStatus($context, $adGroupId, $criterionId, $status);
            }

            public function addNegativeKeyword(GoogleAdsAccessContext $context, GoogleAdsKeywordLevel $scope, string $parentId, string $text, GoogleAdsMatchType $matchType): GoogleAdsMutationResult
            {
                $this->levels[] = DB::transactionLevel();

                return $this->inner->addNegativeKeyword($context, $scope, $parentId, $text, $matchType);
            }
        });

        $service = $this->app->make(GoogleAdsMutationService::class);
        $campaign = $this->campaignOf($t['account']);

        $service->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $service->pauseKeyword($t['business'], $t['actor'], $this->positiveKeywordOf($t['account'])->uid);
        $service->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'no transaction');

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

        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);

        $locks = array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'google_ads_accounts') && str_contains($sql, 'for update'));
        $this->assertCount(2, $locks, 'every mutation request takes the account row lock');
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'));
    }

    public function test_a_second_request_arriving_while_the_first_is_in_flight_does_not_send(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $service = $this->service();
        $secondOutcome = null;
        $inner = $this->fakeAds;
        $business = $t['business'];
        $actor = $t['actor'];
        $campaignUid = $campaign->uid;

        // The "concurrent" request runs while request one's provider call is outstanding:
        // the ledger row is already committed as pending, so it must not send.
        $this->app->instance(GoogleAdsMutationClient::class, new class($inner, function () use (&$secondOutcome, $service, $business, $actor, $campaignUid): void {
            $secondOutcome = $service->pauseCampaign($business, $actor, $campaignUid);
        }) implements GoogleAdsMutationClient {
            public function __construct(private $inner, private $during)
            {
            }

            public function setCampaignStatus(GoogleAdsAccessContext $context, string $campaignId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult
            {
                ($this->during)();

                return $this->inner->setCampaignStatus($context, $campaignId, $status);
            }

            public function setKeywordStatus(GoogleAdsAccessContext $context, string $adGroupId, string $criterionId, GoogleAdsEntityStatus $status): GoogleAdsMutationResult
            {
                return $this->inner->setKeywordStatus($context, $adGroupId, $criterionId, $status);
            }

            public function addNegativeKeyword(GoogleAdsAccessContext $context, GoogleAdsKeywordLevel $scope, string $parentId, string $text, GoogleAdsMatchType $matchType): GoogleAdsMutationResult
            {
                return $this->inner->addNegativeKeyword($context, $scope, $parentId, $text, $matchType);
            }
        });

        $first = $this->app->make(GoogleAdsMutationService::class)->pauseCampaign($business, $actor, $campaignUid);

        $this->assertSame(MutationOutcomeStatus::Succeeded, $first->status);
        $this->assertNotNull($secondOutcome);
        $this->assertSame(MutationOutcomeStatus::DuplicateNoop, $secondOutcome->status);
        $this->assertSame(MutationOutcome::KEY_IN_PROGRESS, $secondOutcome->messageKey);
        $this->assertSame(1, $this->fakeAds->callCount('setCampaignStatus'));
    }

    // ---------------------------------------------------------------
    // Safety
    // ---------------------------------------------------------------

    public function test_ledger_and_detail_rows_never_contain_a_token(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);

        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->service()->addNegativeKeyword($t['business'], $t['actor'], $campaign->uid, 'token check');
        $this->fakeAds->failNext('setKeywordStatus', GoogleAdsProviderException::timeout(true));
        $this->service()->pauseKeyword($t['business'], $t['actor'], $this->positiveKeywordOf($t['account'])->uid);

        $dump = json_encode([
            DB::table('business_google_operations')->get()->all(),
            DB::table('google_ads_mutations')->get()->all(),
        ]);

        $this->assertStringNotContainsString('fake-access-token', $dump);
        $this->assertStringNotContainsString('plain-ads-refresh-token', $dump);
        $this->assertStringNotContainsStringIgnoringCase('bearer', $dump);
        $this->assertStringNotContainsStringIgnoringCase('refresh_token', $dump);
    }

    public function test_the_only_mutation_operation_types_are_the_three_documented_ones(): void
    {
        $adsTypes = array_filter(
            array_map(static fn (GoogleOperationType $t): string => $t->value, GoogleOperationType::cases()),
            static fn (string $value): bool => str_starts_with($value, 'ads_'),
        );
        $readOrConnection = ['ads_accounts_listed', 'ads_account_selected', 'ads_sync'];

        $this->assertEqualsCanonicalizing(
            ['ads_campaign_status_changed', 'ads_keyword_status_changed', 'ads_negative_keyword_added'],
            array_values(array_diff($adsTypes, $readOrConnection)),
        );
        $this->assertEqualsCanonicalizing(
            GoogleAdsMutationOperations::typeValues(),
            array_values(array_diff($adsTypes, $readOrConnection)),
        );
        $this->assertTrue(GoogleAdsMutationOperations::isMutation(GoogleOperationType::AdsNegativeKeywordAdded));
        $this->assertFalse(GoogleAdsMutationOperations::isMutation(GoogleOperationType::AdsSync));
    }

    public function test_the_service_only_ever_writes_enabled_or_paused(): void
    {
        $t = $this->mutationTenant();
        $campaign = $this->campaignOf($t['account']);
        $keyword = $this->positiveKeywordOf($t['account']);

        $this->service()->pauseCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->service()->resumeCampaign($t['business'], $t['actor'], $campaign->uid);
        $this->service()->pauseKeyword($t['business'], $t['actor'], $keyword->uid);
        $this->service()->resumeKeyword($t['business'], $t['actor'], $keyword->uid);

        $sent = array_merge(
            array_column(array_column($this->fakeAds->callsTo('setCampaignStatus'), 'args'), 'status'),
            array_column(array_column($this->fakeAds->callsTo('setKeywordStatus'), 'args'), 'status'),
        );

        $this->assertCount(4, $sent);
        $this->assertEqualsCanonicalizing(['PAUSED', 'ENABLED', 'PAUSED', 'ENABLED'], $sent);
    }
}
