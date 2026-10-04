<?php

namespace App\Library\GoogleAds\Mutations;

use App\DTO\GoogleAds\GoogleAdsAccessContext;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\GoogleAds\GoogleAdsEntityStatus;
use App\Enums\GoogleAds\GoogleAdsKeywordLevel;
use App\Enums\GoogleAds\GoogleAdsMatchType;
use App\Enums\GoogleAds\GoogleAdsMutationKind;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\GoogleAds\Contracts\GoogleAdsMutationClient;
use App\Library\GoogleAds\GoogleAdsCallBudget;
use App\Library\GoogleAds\GoogleAdsConnectionManager;
use App\Library\GoogleAds\GoogleAdsKeywordText;
use App\Library\GoogleAds\GoogleAdsOperationLedger;
use App\Models\Business;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsAdGroup;
use App\Models\GoogleAdsCampaign;
use App\Models\GoogleAdsKeyword;
use App\Models\GoogleAdsMutation;
use App\Models\GoogleAdsSearchTerm;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AccountRepository;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Google Ads Module V1 contract §6 / D8 — the ONLY write path to Google Ads.
 * Five operations: pause/resume a campaign, pause/resume a keyword, add a
 * negative keyword. Nothing else is writable here (no budget, bidding,
 * targeting, creation, or REMOVED).
 *
 * Every operation follows the same order:
 *
 *  1. GATE (fail closed, before anything is opened): the actor holds
 *     `manage_google_ads`; the Business is entitled to GoogleAdsModule
 *     (EntitlementManager, never a plan name).
 *  2. SHORT TRANSACTION: lock the Business's account row; require an active
 *     google_ads connection; resolve the target by uid + business_id +
 *     google_ads_account_id (a foreign uid is "not found"; request-supplied
 *     external ids are never accepted); refuse duplicates and anything in
 *     flight; open the ledger operation and the google_ads_mutations detail
 *     row. Commit.
 *  3. PROVIDER CALL with no transaction open, inside the call budget
 *     (withinOperation, which also covers the access-token exchange).
 *  4. OUTCOME: success -> ledger succeeded + local state written (so the UI
 *     is consistent at once and a retry cannot duplicate); definite rejection
 *     -> failed, local state untouched; rate limit / budget -> deferred, no
 *     retry; ambiguous (sent, no answer) -> `unknown`, local state untouched,
 *     NEVER replayed. A later attempt on a target with a pending/unknown
 *     operation is answered from the ledger, not sent again, until
 *     GoogleAdsMutationReconciler resolves it from Google's synced state.
 *
 * Status and idempotency live in the ledger (D8); google_ads_mutations holds
 * only the target, requested state, parameters, dedupe key and actor.
 */
final class GoogleAdsMutationService
{
    public const PERMISSION = 'manage_google_ads';

    public function __construct(
        private readonly GoogleAdsMutationClient $client,
        private readonly GoogleAdsConnectionManager $connections,
        private readonly GoogleAdsOperationLedger $ledger,
        private readonly GoogleAdsCallBudget $budget,
        private readonly EntitlementManager $entitlements,
        private readonly AccountRepository $accounts,
    ) {
    }

    // ------------------------------------------------------------------
    // Public operations
    // ------------------------------------------------------------------

    public function pauseCampaign(Business $business, User $actor, string $campaignUid): MutationOutcome
    {
        return $this->changeCampaign($business, $actor, $campaignUid, GoogleAdsEntityStatus::Paused);
    }

    public function resumeCampaign(Business $business, User $actor, string $campaignUid): MutationOutcome
    {
        return $this->changeCampaign($business, $actor, $campaignUid, GoogleAdsEntityStatus::Enabled);
    }

    public function pauseKeyword(Business $business, User $actor, string $keywordUid): MutationOutcome
    {
        return $this->changeKeyword($business, $actor, $keywordUid, GoogleAdsEntityStatus::Paused);
    }

    public function resumeKeyword(Business $business, User $actor, string $keywordUid): MutationOutcome
    {
        return $this->changeKeyword($business, $actor, $keywordUid, GoogleAdsEntityStatus::Enabled);
    }

    /**
     * @param  string  $campaignUid  the campaign the term was served in (also the parent for campaign scope)
     * @param  string  $text  the term / keyword text (normalised and validated here)
     * @param  ?int  $searchTermId  local google_ads_search_terms.id the term came from; REQUIRED for ad-group
     *                              scope (it names the ad group) and, when given, must belong to the campaign
     *
     * @throws GoogleAdsMutationException
     */
    public function addNegativeKeyword(
        Business $business,
        User $actor,
        string $campaignUid,
        string $text,
        GoogleAdsKeywordLevel $scope = GoogleAdsKeywordLevel::Campaign,
        GoogleAdsMatchType $matchType = GoogleAdsMatchType::Exact,
        ?int $searchTermId = null,
    ): MutationOutcome {
        return $this->execute($business, $actor, function (GoogleAdsAccount $account) use ($campaignUid, $text, $scope, $matchType, $searchTermId) {
            $negative = $this->resolveNegative($account, $campaignUid, $text, $scope, $matchType, $searchTermId);

            $open = $this->openConflict($account, GoogleAdsMutationKind::NegativeKeyword, $negative['target_type'], $negative['parent']->id, $negative['dedupe_key']);

            if ($open !== null) {
                return $open;
            }

            if ($negative['already_excluded']) {
                return MutationOutcome::alreadyExcluded();
            }

            return $this->negativePlan($account, $negative);
        });
    }

    /**
     * The exact facts the confirmation must show. No provider call and no
     * write; subject to the same gate as the mutation itself.
     *
     * @throws GoogleAdsMutationException
     */
    public function previewNegativeKeyword(
        Business $business,
        User $actor,
        string $campaignUid,
        string $text,
        GoogleAdsKeywordLevel $scope = GoogleAdsKeywordLevel::Campaign,
        GoogleAdsMatchType $matchType = GoogleAdsMatchType::Exact,
        ?int $searchTermId = null,
    ): NegativeKeywordPreview {
        $this->guard($business, $actor);

        $account = $this->accountFor($business, lock: false);
        $negative = $this->resolveNegative($account, $campaignUid, $text, $scope, $matchType, $searchTermId);

        return new NegativeKeywordPreview(
            term: $negative['text'],
            scope: $scope,
            scopeLabel: $scope === GoogleAdsKeywordLevel::Campaign ? 'Campaign' : 'Ad group',
            parentName: (string) $negative['parent']->name,
            matchType: $matchType,
            alreadyExcluded: $negative['already_excluded'],
        );
    }

    // ------------------------------------------------------------------
    // Campaign / keyword status
    // ------------------------------------------------------------------

    private function changeCampaign(Business $business, User $actor, string $campaignUid, GoogleAdsEntityStatus $status): MutationOutcome
    {
        $this->assertWritableStatus($status);

        return $this->execute($business, $actor, function (GoogleAdsAccount $account) use ($campaignUid, $status) {
            $campaign = GoogleAdsCampaign::query()
                ->where('uid', $campaignUid)
                ->where('business_id', $account->business_id)
                ->where('google_ads_account_id', $account->id)
                ->first() ?? throw new GoogleAdsMutationNotFoundException();

            $this->assertChangeable($campaign->status);

            $open = $this->openConflict($account, GoogleAdsMutationKind::CampaignStatus, 'campaign', $campaign->id, null);

            if ($open !== null) {
                return $open;
            }

            if ($campaign->status === $status) {
                return MutationOutcome::alreadyInState();
            }

            $campaignId = (int) $campaign->id;
            $accountId = (int) $account->id;
            $external = (string) $campaign->external_campaign_id;

            return new MutationPlan(
                kind: GoogleAdsMutationKind::CampaignStatus,
                targetType: 'campaign',
                targetLocalId: $campaignId,
                targetResourceName: $campaign->resourceName($account->customer_id),
                requestedState: $status->value,
                params: null,
                dedupeKey: $this->dedupeKey([GoogleAdsMutationKind::CampaignStatus->value, $accountId, 'campaign', $campaignId, $status->value]),
                summary: $this->verb($status) . ' campaign: ' . Str::limit((string) $campaign->name, 80, '...'),
                send: fn (GoogleAdsMutationClient $client, GoogleAdsAccessContext $context) => $client->setCampaignStatus($context, $external, $status),
                applyLocally: function () use ($campaignId, $accountId, $status): void {
                    GoogleAdsCampaign::query()
                        ->whereKey($campaignId)
                        ->where('google_ads_account_id', $accountId)
                        ->update(['status' => $status->value]);
                },
            );
        });
    }

    private function changeKeyword(Business $business, User $actor, string $keywordUid, GoogleAdsEntityStatus $status): MutationOutcome
    {
        $this->assertWritableStatus($status);

        return $this->execute($business, $actor, function (GoogleAdsAccount $account) use ($keywordUid, $status) {
            $keyword = GoogleAdsKeyword::query()
                ->with('adGroup')
                ->where('uid', $keywordUid)
                ->where('business_id', $account->business_id)
                ->where('google_ads_account_id', $account->id)
                ->first() ?? throw new GoogleAdsMutationNotFoundException();

            // Only a positive ad-group keyword can be paused/resumed: a negative is
            // add-only here, and campaign-level criteria are negatives.
            if ($keyword->is_negative || $keyword->level !== GoogleAdsKeywordLevel::AdGroup || $keyword->adGroup === null) {
                throw new GoogleAdsMutationValidationException('keyword_not_changeable');
            }

            $this->assertChangeable($keyword->status);

            $tail = explode('~', (string) $keyword->external_criterion_id);

            if (count($tail) !== 2 || $tail[0] !== (string) $keyword->adGroup->external_ad_group_id || ! ctype_digit($tail[1])) {
                throw new GoogleAdsMutationValidationException('keyword_not_changeable');
            }

            $open = $this->openConflict($account, GoogleAdsMutationKind::KeywordStatus, 'keyword', $keyword->id, null);

            if ($open !== null) {
                return $open;
            }

            if ($keyword->status === $status) {
                return MutationOutcome::alreadyInState();
            }

            $keywordId = (int) $keyword->id;
            $accountId = (int) $account->id;
            $adGroupExternal = $tail[0];
            $criterionId = $tail[1];

            return new MutationPlan(
                kind: GoogleAdsMutationKind::KeywordStatus,
                targetType: 'keyword',
                targetLocalId: $keywordId,
                targetResourceName: 'customers/' . $account->customer_id . '/adGroupCriteria/' . $keyword->external_criterion_id,
                requestedState: $status->value,
                params: null,
                dedupeKey: $this->dedupeKey([GoogleAdsMutationKind::KeywordStatus->value, $accountId, 'keyword', $keywordId, $status->value]),
                summary: $this->verb($status) . ' keyword: ' . Str::limit((string) $keyword->text, 80, '...'),
                send: fn (GoogleAdsMutationClient $client, GoogleAdsAccessContext $context) => $client->setKeywordStatus($context, $adGroupExternal, $criterionId, $status),
                applyLocally: function () use ($keywordId, $accountId, $status): void {
                    GoogleAdsKeyword::query()
                        ->whereKey($keywordId)
                        ->where('google_ads_account_id', $accountId)
                        ->update(['status' => $status->value]);
                },
            );
        });
    }

    // ------------------------------------------------------------------
    // Negative keyword
    // ------------------------------------------------------------------

    /**
     * @return array{text: string, scope: GoogleAdsKeywordLevel, match: GoogleAdsMatchType, campaign: GoogleAdsCampaign, parent: GoogleAdsCampaign|GoogleAdsAdGroup, target_type: string, dedupe_key: string, already_excluded: bool}
     */
    private function resolveNegative(
        GoogleAdsAccount $account,
        string $campaignUid,
        string $text,
        GoogleAdsKeywordLevel $scope,
        GoogleAdsMatchType $matchType,
        ?int $searchTermId,
    ): array {
        $normalized = GoogleAdsKeywordText::normalize($text)
            ?? throw new GoogleAdsMutationValidationException('invalid_keyword_text', 'That text cannot be used as a negative keyword.');

        $campaign = GoogleAdsCampaign::query()
            ->where('uid', $campaignUid)
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->first() ?? throw new GoogleAdsMutationNotFoundException();

        $this->assertChangeable($campaign->status);

        $term = null;

        if ($searchTermId !== null) {
            $term = GoogleAdsSearchTerm::query()
                ->whereKey($searchTermId)
                ->where('business_id', $account->business_id)
                ->where('google_ads_account_id', $account->id)
                ->where('google_ads_campaign_id', $campaign->id)
                ->first() ?? throw new GoogleAdsMutationNotFoundException();
        }

        if ($scope === GoogleAdsKeywordLevel::AdGroup) {
            if ($term === null) {
                throw new GoogleAdsMutationValidationException('ad_group_scope_requires_search_term');
            }

            $parent = GoogleAdsAdGroup::query()
                ->whereKey($term->google_ads_ad_group_id)
                ->where('business_id', $account->business_id)
                ->where('google_ads_account_id', $account->id)
                ->where('google_ads_campaign_id', $campaign->id)
                ->first() ?? throw new GoogleAdsMutationNotFoundException();

            $this->assertChangeable($parent->status);
        } else {
            $parent = $campaign;
        }

        $targetType = $scope === GoogleAdsKeywordLevel::AdGroup ? 'ad_group' : 'campaign';
        $dedupeKey = $this->dedupeKey([
            GoogleAdsMutationKind::NegativeKeyword->value, (int) $account->id, $targetType, (int) $parent->id,
            $scope->value, $matchType->value, mb_strtolower($normalized),
        ]);

        return [
            'text' => $normalized,
            'scope' => $scope,
            'match' => $matchType,
            'campaign' => $campaign,
            'parent' => $parent,
            'target_type' => $targetType,
            'dedupe_key' => $dedupeKey,
            'already_excluded' => $this->negativeAlreadyPresent($account, $scope, $parent, $matchType, $normalized, $dedupeKey),
        ];
    }

    /** A synced (or locally recorded) live negative, or a previously succeeded operation for the same key. */
    private function negativeAlreadyPresent(
        GoogleAdsAccount $account,
        GoogleAdsKeywordLevel $scope,
        GoogleAdsCampaign|GoogleAdsAdGroup $parent,
        GoogleAdsMatchType $matchType,
        string $text,
        string $dedupeKey,
    ): bool {
        $synced = GoogleAdsKeyword::query()
            ->where('business_id', $account->business_id)
            ->where('google_ads_account_id', $account->id)
            ->where('is_negative', true)
            ->where('level', $scope->value)
            ->where('match_type', $matchType->value)
            ->where('status', '!=', GoogleAdsEntityStatus::Removed->value)
            ->where($scope === GoogleAdsKeywordLevel::AdGroup ? 'google_ads_ad_group_id' : 'google_ads_campaign_id', $parent->id)
            ->whereRaw('LOWER(text) = ?', [mb_strtolower($text)])
            ->exists();

        if ($synced) {
            return true;
        }

        return GoogleAdsMutation::query()
            ->where('google_ads_account_id', $account->id)
            ->where('kind', GoogleAdsMutationKind::NegativeKeyword->value)
            ->where('dedupe_key', $dedupeKey)
            ->whereHas('operation', fn ($operation) => $operation->where('status', GoogleOperationStatus::Succeeded->value))
            ->exists();
    }

    /**
     * @param  array{text: string, scope: GoogleAdsKeywordLevel, match: GoogleAdsMatchType, campaign: GoogleAdsCampaign, parent: GoogleAdsCampaign|GoogleAdsAdGroup, target_type: string, dedupe_key: string, already_excluded: bool}  $negative
     */
    private function negativePlan(GoogleAdsAccount $account, array $negative): MutationPlan
    {
        $scope = $negative['scope'];
        $matchType = $negative['match'];
        $text = $negative['text'];
        $accountId = (int) $account->id;
        $businessId = (int) $account->business_id;
        $campaignId = (int) $negative['campaign']->id;
        $parent = $negative['parent'];
        $parentLocalId = (int) $parent->id;
        $adGroupId = $scope === GoogleAdsKeywordLevel::AdGroup ? $parentLocalId : null;
        $parentExternal = $scope === GoogleAdsKeywordLevel::AdGroup
            ? (string) $parent->external_ad_group_id
            : (string) $parent->external_campaign_id;

        return new MutationPlan(
            kind: GoogleAdsMutationKind::NegativeKeyword,
            targetType: $negative['target_type'],
            targetLocalId: $parentLocalId,
            targetResourceName: 'customers/' . $account->customer_id . ($scope === GoogleAdsKeywordLevel::AdGroup ? '/adGroups/' : '/campaigns/') . $parentExternal,
            requestedState: null,
            params: [
                'text' => $text,
                'match_type' => $matchType->value,
                'scope' => $scope->value,
                'campaign_local_id' => $campaignId,
                'ad_group_local_id' => $adGroupId,
            ],
            dedupeKey: $negative['dedupe_key'],
            summary: 'Add negative keyword [' . $matchType->value . '] ' . Str::limit($text, 60, '...') . ' (' . ($scope === GoogleAdsKeywordLevel::AdGroup ? 'ad group' : 'campaign') . ')',
            send: fn (GoogleAdsMutationClient $client, GoogleAdsAccessContext $context) => $client->addNegativeKeyword($context, $scope, $parentExternal, $text, $matchType),
            applyLocally: function ($result) use ($scope, $matchType, $text, $accountId, $businessId, $campaignId, $adGroupId): void {
                $tail = Str::afterLast((string) $result->resourceName, '/');

                // Without a usable id the next sync inserts it; the succeeded ledger row
                // already stops a duplicate being sent.
                if (preg_match('/^\d+~\d+$/', $tail) !== 1) {
                    return;
                }

                GoogleAdsKeyword::query()->updateOrCreate(
                    ['google_ads_account_id' => $accountId, 'level' => $scope->value, 'external_criterion_id' => $tail],
                    [
                        'business_id' => $businessId,
                        'google_ads_campaign_id' => $campaignId,
                        'google_ads_ad_group_id' => $adGroupId,
                        'text' => $text,
                        'match_type' => $matchType->value,
                        'status' => GoogleAdsEntityStatus::Enabled->value,
                        'is_negative' => true,
                    ],
                );
            },
        );
    }

    // ------------------------------------------------------------------
    // Shared pipeline
    // ------------------------------------------------------------------

    /**
     * @param  Closure(GoogleAdsAccount): (MutationPlan|MutationOutcome)  $plan  runs inside the locked transaction
     */
    private function execute(Business $business, User $actor, Closure $plan): MutationOutcome
    {
        $this->guard($business, $actor);

        $prepared = DB::transaction(function () use ($business, $actor, $plan) {
            $account = $this->accountFor($business, lock: true);
            $connection = $this->activeConnection($account);
            $result = $plan($account);

            if ($result instanceof MutationOutcome) {
                return $result;
            }

            $operation = $this->ledger->open(
                (int) $business->id,
                $result->kind->operationType(),
                (int) $actor->id,
                $result->summary,
                [$result->kind->value, $result->targetResourceName],
            );

            GoogleAdsMutation::query()->create([
                'business_id' => $account->business_id,
                'google_ads_account_id' => $account->id,
                'business_google_operation_id' => $operation->id,
                'kind' => $result->kind->value,
                'target_type' => $result->targetType,
                'target_local_id' => $result->targetLocalId,
                'target_resource_name' => $result->targetResourceName,
                'requested_state' => $result->requestedState,
                'params' => $result->params,
                'dedupe_key' => $result->dedupeKey,
                'actor_user_id' => $actor->id,
            ]);

            return [$account, $connection, $operation, $result];
        });

        if ($prepared instanceof MutationOutcome) {
            return $prepared;
        }

        [$account, $connection, $operation, $mutationPlan] = $prepared;

        return $this->send($account, $connection, $operation, $mutationPlan);
    }

    /** The provider call. No transaction is open here. */
    private function send(GoogleAdsAccount $account, BusinessGoogleConnection $connection, BusinessGoogleOperation $operation, MutationPlan $plan): MutationOutcome
    {
        try {
            $result = $this->budget->withinOperation($connection, $operation, function () use ($account, $connection, $plan) {
                $context = new GoogleAdsAccessContext(
                    $this->connections->accessTokenFor($connection),
                    (string) $account->customer_id,
                    $account->login_customer_id,
                );

                return ($plan->send)($this->client, $context);
            });
        } catch (GoogleAdsProviderException $exception) {
            $operation = $this->ledger->fail($operation, $exception, $plan->summary);

            return MutationOutcome::fromFailure($operation, $exception);
        } catch (Throwable $throwable) {
            // We cannot tell whether a request left the process: the safe reading is "unknown".
            $this->ledger->fail($operation, GoogleAdsProviderException::timeout(true), $plan->summary);

            throw $throwable;
        }

        // provider_operation_reference is globally unique: only a CREATED criterion has a
        // resource name of its own. A status change re-addresses the same campaign / keyword
        // resource every time, so it records none (the target lives in google_ads_mutations).
        $this->ledger->succeed(
            $operation,
            $plan->summary,
            $plan->kind === GoogleAdsMutationKind::NegativeKeyword ? $result->resourceName : null,
        );
        ($plan->applyLocally)($result);

        return MutationOutcome::succeeded($operation);
    }

    /**
     * An operation for this target that is still pending or unknown blocks a
     * second send: unknown -> awaiting confirmation, pending -> in progress.
     * For negatives the key is the dedupe key; for status changes it is the
     * target (a pending pause also blocks a resume until it settles).
     */
    private function openConflict(GoogleAdsAccount $account, GoogleAdsMutationKind $kind, string $targetType, int $targetLocalId, ?string $dedupeKey): ?MutationOutcome
    {
        $query = GoogleAdsMutation::query()
            ->with('operation')
            ->where('google_ads_account_id', $account->id)
            ->where('kind', $kind->value)
            ->where('target_type', $targetType)
            ->where('target_local_id', $targetLocalId)
            ->whereHas('operation', fn ($operation) => $operation->whereIn('status', [
                GoogleOperationStatus::Pending->value,
                GoogleOperationStatus::Unknown->value,
            ]));

        if ($dedupeKey !== null) {
            $query->where('dedupe_key', $dedupeKey);
        }

        $existing = $query->orderByDesc('id')->first();

        if ($existing === null || $existing->operation === null) {
            return null;
        }

        return $existing->operation->status === GoogleOperationStatus::Unknown
            ? MutationOutcome::awaitingConfirmation($existing->operation)
            : MutationOutcome::inProgress($existing->operation);
    }

    // ------------------------------------------------------------------
    // Gates and resolution
    // ------------------------------------------------------------------

    private function guard(Business $business, User $actor): void
    {
        if (! $this->accounts->hasPermission($actor, self::PERMISSION)) {
            throw new GoogleAdsMutationForbiddenException();
        }

        try {
            $workspace = $business->workspace_id === null ? null : Workspace::query()->find($business->workspace_id);

            $entitled = $workspace !== null
                && $workspace->is_active
                && $this->entitlements->decide($workspace, $business, PlatformFeature::GoogleAdsModule->value, (int) $actor->id)->allowed;
        } catch (Throwable) {
            $entitled = false;
        }

        if (! $entitled) {
            throw new GoogleAdsMutationNotEntitledException();
        }
    }

    private function accountFor(Business $business, bool $lock): GoogleAdsAccount
    {
        $query = GoogleAdsAccount::query()->where('business_id', $business->id);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new GoogleAdsMutationNotFoundException('account_not_found');
    }

    private function activeConnection(GoogleAdsAccount $account): BusinessGoogleConnection
    {
        $connection = BusinessGoogleConnection::query()
            ->whereKey($account->business_google_connection_id)
            ->where('business_id', $account->business_id)
            ->where('product', GoogleConnectionProduct::GoogleAds->value)
            ->first();

        if ($connection === null || ! $connection->isActive() || ! $connection->hasStoredAuthorization()) {
            throw new GoogleAdsMutationConnectionException();
        }

        return $connection;
    }

    private function assertWritableStatus(GoogleAdsEntityStatus $status): void
    {
        if (! $status->isWritable()) {
            throw new GoogleAdsMutationValidationException('status_not_writable');
        }
    }

    /** A REMOVED (or unknown) entity can neither be paused nor resumed. */
    private function assertChangeable(GoogleAdsEntityStatus $current): void
    {
        if (! $current->isWritable()) {
            throw new GoogleAdsMutationValidationException('entity_not_changeable', 'That item has been removed in Google Ads and cannot be changed.');
        }
    }

    private function verb(GoogleAdsEntityStatus $status): string
    {
        return $status === GoogleAdsEntityStatus::Paused ? 'Pause' : 'Resume';
    }

    /** @param  array<int, string|int>  $parts */
    private function dedupeKey(array $parts): string
    {
        return hash('sha256', implode('|', $parts));
    }
}
