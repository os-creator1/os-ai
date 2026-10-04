<?php

namespace App\Library\MetaAds\Mutations;

use App\Enums\MetaAds\MetaAdsMutationTargetType;
use App\Enums\MetaAds\MetaAdsRequestedState;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaAdsConcurrencyException;
use App\Exceptions\MetaAds\MetaAdsMutationConnectionException;
use App\Exceptions\MetaAds\MetaAdsMutationForbiddenException;
use App\Exceptions\MetaAds\MetaAdsMutationNotEntitledException;
use App\Exceptions\MetaAds\MetaAdsMutationNotFoundException;
use App\Exceptions\MetaAds\MetaAdsMutationValidationException;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\MetaAds\Contracts\MetaMutationClient;
use App\Library\MetaAds\MetaAdsCallBudget;
use App\Library\MetaAds\MetaAdsConnectionManager;
use App\Library\MetaAds\MetaAdsOperationLedger;
use App\Models\Business;
use App\Models\BusinessMetaConnection;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsAd;
use App\Models\MetaAdsAdSet;
use App\Models\MetaAdsCampaign;
use App\Models\MetaAdsMutation;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\AccountRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Meta Ads Module V1 contract 24 §7 — the ONLY write path to Meta Ads.
 * Six operations: pause / resume a campaign, an ad set or an ad. Nothing else
 * is writable (no budget, bid, targeting, creative, creation or deletion).
 *
 * Same order as GoogleAdsMutationService (contract 23 §6):
 *
 *  1. GATE (fail closed, before anything is opened): the actor holds
 *     `manage_meta_ads`; the Business holds the full Ads capability
 *     (AdsFeatureAccess, never a plan name).
 *  2. SHORT TRANSACTION: lock the Business's SELECTED account row; require an
 *     active connection that may write (`ads_management` granted); resolve the
 *     target by uid + business_id + meta_ads_account_id (a foreign uid is "not
 *     found"; request-supplied external ids are never accepted); validate the
 *     transition; refuse anything already in flight for the target; open the
 *     ledger operation and the meta_ads_mutations detail row. Commit.
 *  3. PROVIDER CALL with no transaction open, inside the call budget
 *     (withinOperation, which also covers token reading).
 *  4. OUTCOME: success -> ledger succeeded + the target's local `status`
 *     written; definite rejection -> failed, local state untouched;
 *     rate limit / our budget -> deferred, never retried inline; ambiguous
 *     (sent, no answer) -> `unknown`, local state untouched, NEVER replayed;
 *     a dead token -> the connection is marked expired/revoked and the
 *     operation failed with a reconnect message.
 *
 * Child state is never invented: pausing a campaign writes only that
 * campaign's `status`; ad sets / ads (and every `effective_status`, which is
 * a provider-reported fact) are refreshed by the next sync.
 *
 * Status and idempotency live in the ledger; meta_ads_mutations holds only the
 * target, requested state, dedupe key and actor.
 */
final class MetaAdsMutationService
{
    public const PERMISSION = 'manage_meta_ads';

    public function __construct(
        private readonly MetaMutationClient $client,
        private readonly MetaAdsConnectionManager $connections,
        private readonly MetaAdsOperationLedger $ledger,
        private readonly MetaAdsCallBudget $budget,
        private readonly AdsFeatureAccess $access,
        private readonly AccountRepository $accounts,
    ) {
    }

    // ------------------------------------------------------------------
    // Public operations
    // ------------------------------------------------------------------

    public function pauseCampaign(Business $business, ?Workspace $workspace, int $actorUserId, string $uid): MetaAdsMutationOutcome
    {
        return $this->change($business, $workspace, $actorUserId, $uid, MetaAdsMutationTargetType::Campaign, MetaAdsRequestedState::Paused);
    }

    public function resumeCampaign(Business $business, ?Workspace $workspace, int $actorUserId, string $uid): MetaAdsMutationOutcome
    {
        return $this->change($business, $workspace, $actorUserId, $uid, MetaAdsMutationTargetType::Campaign, MetaAdsRequestedState::Active);
    }

    public function pauseAdSet(Business $business, ?Workspace $workspace, int $actorUserId, string $uid): MetaAdsMutationOutcome
    {
        return $this->change($business, $workspace, $actorUserId, $uid, MetaAdsMutationTargetType::AdSet, MetaAdsRequestedState::Paused);
    }

    public function resumeAdSet(Business $business, ?Workspace $workspace, int $actorUserId, string $uid): MetaAdsMutationOutcome
    {
        return $this->change($business, $workspace, $actorUserId, $uid, MetaAdsMutationTargetType::AdSet, MetaAdsRequestedState::Active);
    }

    public function pauseAd(Business $business, ?Workspace $workspace, int $actorUserId, string $uid): MetaAdsMutationOutcome
    {
        return $this->change($business, $workspace, $actorUserId, $uid, MetaAdsMutationTargetType::Ad, MetaAdsRequestedState::Paused);
    }

    public function resumeAd(Business $business, ?Workspace $workspace, int $actorUserId, string $uid): MetaAdsMutationOutcome
    {
        return $this->change($business, $workspace, $actorUserId, $uid, MetaAdsMutationTargetType::Ad, MetaAdsRequestedState::Active);
    }

    // ------------------------------------------------------------------
    // Pipeline
    // ------------------------------------------------------------------

    private function change(
        Business $business,
        ?Workspace $workspace,
        int $actorUserId,
        string $uid,
        MetaAdsMutationTargetType $targetType,
        MetaAdsRequestedState $requested,
    ): MetaAdsMutationOutcome {
        $this->guard($business, $workspace, $actorUserId);

        $prepared = DB::transaction(function () use ($business, $actorUserId, $uid, $targetType, $requested) {
            $account = $this->accountFor($business, lock: true);
            $connection = $this->writableConnection($account);

            $result = $this->plan($account, $uid, $targetType, $requested);

            if ($result instanceof MetaAdsMutationOutcome) {
                return $result;
            }

            $operation = $this->ledger->open(
                (int) $business->id,
                $result->operationType,
                $actorUserId,
                $result->summary,
                [$result->operationType->value, $result->targetType->value, (string) $result->targetLocalId, $requested->value],
            );

            MetaAdsMutation::query()->create([
                'business_id' => $account->business_id,
                'meta_ads_account_id' => $account->id,
                'business_meta_operation_id' => $operation->id,
                'target_type' => $result->targetType->value,
                'target_local_id' => $result->targetLocalId,
                'requested_state' => $requested->value,
                'dedupe_key' => $result->dedupeKey,
                'actor_user_id' => $actorUserId,
            ]);

            return [$account, $connection, $operation, $result];
        });

        if ($prepared instanceof MetaAdsMutationOutcome) {
            return $prepared;
        }

        [$account, $connection, $operation, $plan] = $prepared;

        return $this->send($account, $connection, $operation, $plan);
    }

    /**
     * Runs inside the locked transaction: resolves + validates the target.
     */
    private function plan(MetaAdsAccount $account, string $uid, MetaAdsMutationTargetType $targetType, MetaAdsRequestedState $requested): MetaAdsMutationPlan|MetaAdsMutationOutcome
    {
        /** @var class-string<Model> $model */
        $model = match ($targetType) {
            MetaAdsMutationTargetType::Campaign => MetaAdsCampaign::class,
            MetaAdsMutationTargetType::AdSet => MetaAdsAdSet::class,
            MetaAdsMutationTargetType::Ad => MetaAdsAd::class,
        };

        $target = $model::query()
            ->where('uid', $uid)
            ->where('business_id', $account->business_id)
            ->where('meta_ads_account_id', $account->id)
            ->first() ?? throw new MetaAdsMutationNotFoundException();

        $status = (string) $target->status;

        // DELETED / ARCHIVED (or any status we do not know how to move) can be neither paused nor resumed.
        if (! in_array($status, ['ACTIVE', 'PAUSED'], true)) {
            throw new MetaAdsMutationValidationException('entity_not_changeable', 'That item has been deleted or archived in Meta Ads and cannot be changed.');
        }

        $open = $this->openConflict($account, $targetType, (int) $target->id);

        if ($open !== null) {
            return $open;
        }

        if ($status === $requested->providerValue()) {
            return MetaAdsMutationOutcome::alreadyInState();
        }

        if ($requested === MetaAdsRequestedState::Active
            && $targetType === MetaAdsMutationTargetType::Ad
            && in_array((string) $target->effective_status, ['DISAPPROVED', 'PENDING_REVIEW'], true)) {
            throw new MetaAdsMutationValidationException('ad_not_resumable', 'This ad is not approved yet and cannot be resumed.');
        }

        $localId = (int) $target->id;
        $accountId = (int) $account->id;
        $external = match ($targetType) {
            MetaAdsMutationTargetType::Campaign => (string) $target->external_campaign_id,
            MetaAdsMutationTargetType::AdSet => (string) $target->external_ad_set_id,
            MetaAdsMutationTargetType::Ad => (string) $target->external_ad_id,
        };

        return new MetaAdsMutationPlan(
            operationType: match ($targetType) {
                MetaAdsMutationTargetType::Campaign => MetaOperationType::CampaignStatusChanged,
                MetaAdsMutationTargetType::AdSet => MetaOperationType::AdSetStatusChanged,
                MetaAdsMutationTargetType::Ad => MetaOperationType::AdStatusChanged,
            },
            targetType: $targetType,
            targetLocalId: $localId,
            externalId: $external,
            requestedState: $requested,
            dedupeKey: hash('sha256', implode('|', ['meta_status', $accountId, $targetType->value, $localId, $requested->value])),
            summary: ($requested === MetaAdsRequestedState::Paused ? 'Pause ' : 'Resume ') . str_replace('_', ' ', $targetType->value) . ' #' . $localId,
            applyLocally: function () use ($model, $localId, $accountId, $requested): void {
                $model::query()
                    ->whereKey($localId)
                    ->where('meta_ads_account_id', $accountId)
                    ->update(['status' => $requested->providerValue()]);
            },
        );
    }

    /** The provider call. No transaction is open here. */
    private function send(MetaAdsAccount $account, BusinessMetaConnection $connection, BusinessMetaOperation $operation, MetaAdsMutationPlan $plan): MetaAdsMutationOutcome
    {
        try {
            $this->budget->withinOperation($connection, $operation, function () use ($account, $connection, $plan) {
                return $this->client->setStatus(
                    $this->connections->accessTokenFor($connection),
                    (string) $account->ad_account_id,
                    $plan->externalId,
                    $plan->targetType->value,
                    $plan->requestedState->providerValue(),
                );
            });
        } catch (MetaProviderException $exception) {
            $operation = $this->ledger->fail($operation, $exception, $plan->summary);

            if ($exception->isTokenFailure()) {
                try {
                    $this->connections->markTokenFailure($connection, $exception);
                } catch (MetaAdsConcurrencyException) {
                    // Another writer already moved the connection; the failure stands.
                }
            }

            return MetaAdsMutationOutcome::fromFailure($operation, $exception);
        } catch (Throwable $throwable) {
            // We cannot tell whether a request left the process: the safe reading is "unknown".
            $this->ledger->fail($operation, MetaProviderException::timeout(true), $plan->summary);

            throw $throwable;
        }

        // A status change re-addresses the same object every time and Meta returns no
        // reference of its own, so none is recorded (the target lives in meta_ads_mutations).
        $this->ledger->succeed($operation, $plan->summary);
        ($plan->applyLocally)();

        return MetaAdsMutationOutcome::succeeded($operation);
    }

    /**
     * An operation for this target that is still pending or unknown blocks a
     * second send (either direction): unknown -> awaiting confirmation,
     * pending -> in progress.
     */
    private function openConflict(MetaAdsAccount $account, MetaAdsMutationTargetType $targetType, int $targetLocalId): ?MetaAdsMutationOutcome
    {
        $existing = MetaAdsMutation::query()
            ->with('operation')
            ->where('meta_ads_account_id', $account->id)
            ->where('target_type', $targetType->value)
            ->where('target_local_id', $targetLocalId)
            ->whereHas('operation', fn ($operation) => $operation->whereIn('status', [
                MetaOperationStatus::Pending->value,
                MetaOperationStatus::Unknown->value,
            ]))
            ->orderByDesc('id')
            ->first();

        if ($existing === null || $existing->operation === null) {
            return null;
        }

        return $existing->operation->status === MetaOperationStatus::Unknown
            ? MetaAdsMutationOutcome::awaitingConfirmation($existing->operation)
            : MetaAdsMutationOutcome::inProgress($existing->operation);
    }

    // ------------------------------------------------------------------
    // Gates and resolution
    // ------------------------------------------------------------------

    private function guard(Business $business, ?Workspace $workspace, int $actorUserId): void
    {
        $actor = User::query()->find($actorUserId);

        if ($actor === null || ! $this->accounts->hasPermission($actor, self::PERMISSION)) {
            throw new MetaAdsMutationForbiddenException();
        }

        try {
            $workspace ??= $business->workspace_id === null ? null : Workspace::query()->find($business->workspace_id);

            $entitled = $workspace !== null
                && (int) $workspace->id === (int) $business->workspace_id
                && $workspace->is_active
                && $this->access->hasFullModule($workspace, $business, $actorUserId);
        } catch (Throwable) {
            $entitled = false;
        }

        if (! $entitled) {
            throw new MetaAdsMutationNotEntitledException();
        }
    }

    private function accountFor(Business $business, bool $lock): MetaAdsAccount
    {
        // An unselected account (disconnected / revoked) is no account to write to.
        $query = MetaAdsAccount::query()->where('business_id', $business->id)->whereNotNull('selected_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new MetaAdsMutationNotFoundException('account_not_found');
    }

    private function writableConnection(MetaAdsAccount $account): BusinessMetaConnection
    {
        $connection = BusinessMetaConnection::query()
            ->whereKey($account->business_meta_connection_id)
            ->where('business_id', $account->business_id)
            ->first();

        if ($connection === null || ! $connection->isActive() || ! $connection->hasStoredAuthorization()) {
            throw new MetaAdsMutationConnectionException(MetaAdsMutationConnectionException::NOT_ACTIVE);
        }

        if ($account->selected_meta_user_id !== null && (string) $account->selected_meta_user_id !== (string) $connection->meta_user_id) {
            throw new MetaAdsMutationConnectionException(MetaAdsMutationConnectionException::MISMATCH);
        }

        if (! $this->connections->canManage($connection)) {
            throw new MetaAdsMutationConnectionException(MetaAdsMutationConnectionException::READ_ONLY);
        }

        return $connection;
    }
}
