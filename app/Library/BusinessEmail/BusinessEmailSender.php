<?php

namespace App\Library\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailOutbound;
use App\DTO\BusinessEmail\BusinessEmailSendRequest;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Exceptions\BusinessEmail\BusinessEmailSendRefusedException;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE canonical Business → Contact email boundary. The manual Settings → Email
 * form calls it today; the future Automations `Send email` executor calls the
 * very same method. It receives application identity (Business, Contact,
 * optional Location, an operation key) and never a provider, an account id or
 * a credential: it resolves the default sender, the recipient address and the
 * provider adapter itself.
 *
 * IDEMPOTENCY. One logical send = one `business_email_messages` row, unique on
 * (business_id, operation_key). The row is created BEFORE the provider call,
 * the provider call is made outside any transaction, and the outcome is
 * written back conditionally on the claim. Calling send() again with the same
 * key never produces a second provider call unless the first attempt FAILED
 * with a retryable category, has attempts left, and its backoff has elapsed.
 *
 * WHAT THE STATES MEAN (BusinessEmailMessageStatus): `accepted` is the
 * provider's API accepting the request — not delivery; nothing is ever marked
 * delivered. A crash or an ambiguous timeout after dispatch is `unconfirmed`:
 * the provider may have sent it, so it is NEVER automatically re-sent.
 *
 * Pre-flight refusals (no account, foreign/unknown Contact, no usable address,
 * invalid content, abuse bound) throw BusinessEmailSendRefusedException with
 * ZERO side effects. Provider-stage failures are persisted on the row.
 */
final class BusinessEmailSender
{
    public function __construct(
        private readonly BusinessEmailSenderResolver $senders,
        private readonly BusinessEmailContactResolver $contacts,
        private readonly BusinessEmailAccountManager $accounts,
        private readonly BusinessEmailProviderRegistry $providers,
    ) {
    }

    /**
     * @throws BusinessEmailSendRefusedException
     */
    public function send(BusinessEmailSendRequest $request): BusinessEmailMessage
    {
        $business = $request->business;
        $operationKey = trim($request->operationKey);

        if ($operationKey === '' || strlen($operationKey) > 191) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::MessageInvalid);
        }

        // The Contact is RE-DERIVED from persistence, scoped to this Business.
        // The caller's model is only a pointer (its id): its business_id,
        // location_id and any cached email are never trusted, so a forged or
        // stale model can neither reach another Business's Contact nor send to
        // an outdated address or Location. A foreign, deleted or unknown
        // Contact is indistinguishable.
        $contact = $this->authoritativeContact($request);

        [$subject, $body] = $this->validatedContent($request);

        // A replay is answered from the recorded row, so it still works after
        // the account was disconnected. It must describe the SAME logical
        // request that created the row: a key is never reusable with
        // materially different input, and a mismatch never reaches a provider.
        $existing = BusinessEmailMessage::query()
            ->where('business_id', $business->id)
            ->where('operation_key', $operationKey)
            ->first();

        if ($existing !== null) {
            $this->assertSameLogicalRequest($existing, $request, $contact, $subject, $body);

            return $this->continueExisting($existing, $request);
        }

        $location = $this->resolveLocation($request, $contact);

        $account = $this->senders->defaultFor($business, $location);

        if ($account === null) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::DisconnectedAccount);
        }

        $to = $this->contacts->singleAddressFor($contact);

        if ($to === null) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::RecipientInvalid);
        }

        $this->assertWithinSendLimits($business, $contact);

        $message = $this->createQueued($request, $contact, $operationKey, $account, $location, $to, $subject, $body);

        // ONE invariant for both paths to a recorded row. createQueued() returns
        // either the row THIS request inserted or, when it lost a concurrent
        // UNIQUE(business_id, operation_key) race, the WINNER's row. A winner
        // is only a replay if it describes the same logical request, so the
        // same check as the ordinary replay path runs before anything can
        // reach a provider. For the request that inserted the row it is
        // trivially true.
        $this->assertSameLogicalRequest($message, $request, $contact, $subject, $body);

        // A row this request inserted is claimed and sent. A race WINNER's row
        // is handled exactly like any recorded row: it may already be sending,
        // accepted or failed, and must never be blindly re-claimed.
        return $message->wasRecentlyCreated
            ? $this->attempt($message, $request)
            : $this->continueExisting($message, $request);
    }

    /**
     * The authoritative Contact: read by id, constrained to the request
     * Business, fresh from the database every time.
     */
    private function authoritativeContact(BusinessEmailSendRequest $request): Contacts
    {
        $contact = Contacts::query()
            ->where('id', $request->contact->id)
            ->where('business_id', $request->business->id)
            ->first();

        if ($contact === null) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::ContactUnavailable);
        }

        return $contact;
    }

    /**
     * Binds a replay to the persisted logical identity of the message: the
     * authoritative Contact, the normalized subject and body, the source, the
     * Automation step identity, and the Location when the caller names one.
     *
     * Deliberately NOT compared: provider state, tokens, attempt counters, the
     * sending user, and a Location the caller left to be derived (the stored
     * Location is the durable snapshot; a Contact who moved since must not turn
     * an honest retry into a conflict).
     */
    private function assertSameLogicalRequest(
        BusinessEmailMessage $message,
        BusinessEmailSendRequest $request,
        Contacts $contact,
        string $subject,
        string $body,
    ): void {
        $same = (int) $message->contact_id === (int) $contact->id
            && (string) $message->subject === $subject
            && (string) $message->body_text === $body
            && $message->source === $request->source
            && ($message->automation_step_run_id === null ? null : (int) $message->automation_step_run_id)
                === ($request->automationStepRunId === null ? null : (int) $request->automationStepRunId)
            && ($request->location === null || (int) $message->location_id === (int) $request->location->id);

        if (! $same) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::IdempotencyConflict);
        }
    }
    /** @return array{0: string, 1: string} */
    private function validatedContent(BusinessEmailSendRequest $request): array
    {
        $subject = trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $request->subject));
        $body = trim(str_replace("\0", '', $request->bodyText));

        if ($subject === ''
            || $body === ''
            || mb_strlen($subject) > (int) config('business_email.send.max_subject_length', 200)
            || mb_strlen($body) > (int) config('business_email.send.max_body_length', 20000)) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::MessageInvalid);
        }

        return [$subject, $body];
    }

    /**
     * The durable Location attribution for this send, snapshotted now. V1:
     * every operational record resolves to exactly ONE Location, so there is
     * no unscoped outcome. In order:
     *
     *  1. the caller's explicit Location (must be this Business's ACTIVE one,
     *     else the send is refused);
     *  2. the authoritative Contact's own Location, when it is a valid active
     *     Location of this Business;
     *  3. the Business's single active Location (the one fallback already
     *     canonical for Contacts and Conversations);
     *  4. otherwise the send is REFUSED (`location_required`): several active
     *     Locations and none provable, or none at all. It is never guessed and
     *     never persisted as NULL.
     *
     * Never re-derived from the Contact afterwards.
     */
    private function resolveLocation(BusinessEmailSendRequest $request, Contacts $contact): BusinessLocation
    {
        $business = $request->business;

        if ($request->location !== null) {
            $location = BusinessLocation::query()->find($request->location->id);

            if ($location === null
                || (int) $location->business_id !== (int) $business->id
                || $this->lifecycleValue($location) !== BusinessLocationLifecycleState::Active->value) {
                throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::MessageInvalid);
            }

            return $location;
        }

        if ($contact->location_id !== null) {
            $location = BusinessLocation::query()->find($contact->location_id);

            if ($location !== null
                && (int) $location->business_id === (int) $business->id
                && $this->lifecycleValue($location) === BusinessLocationLifecycleState::Active->value) {
                return $location;
            }
        }

        $singleId = Contacts::singleActiveLocationIdFor((int) $business->id);
        $single = $singleId !== null ? BusinessLocation::query()->find($singleId) : null;

        if ($single === null) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::LocationRequired);
        }

        return $single;
    }
    private function lifecycleValue(BusinessLocation $location): string
    {
        $state = $location->lifecycle_state;

        return $state instanceof \BackedEnum ? (string) $state->value : (string) $state;
    }

    /**
     * Abuse bounds counted from the ledger itself over the last hour, so one
     * bad workflow (or an impatient user) cannot turn into thousands of
     * emails. A soft bound under concurrency, by design.
     */
    private function assertWithinSendLimits(Business $business, Contacts $contact): void
    {
        $since = now()->subHour();

        $perBusiness = (int) config('business_email.send.per_business_per_hour', 200);

        if (BusinessEmailMessage::query()->where('business_id', $business->id)->where('created_at', '>=', $since)->count() >= $perBusiness) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::SendLimitExceeded);
        }

        $perContact = (int) config('business_email.send.per_contact_per_hour', 5);

        if (BusinessEmailMessage::query()
            ->where('business_id', $business->id)
            ->where('contact_id', $contact->id)
            ->where('created_at', '>=', $since)
            ->count() >= $perContact) {
            throw new BusinessEmailSendRefusedException(BusinessEmailFailureCategory::SendLimitExceeded);
        }
    }

    private function createQueued(
        BusinessEmailSendRequest $request,
        Contacts $contact,
        string $operationKey,
        BusinessEmailAccount $account,
        BusinessLocation $location,
        string $to,
        string $subject,
        string $body,
    ): BusinessEmailMessage {
        try {
            return BusinessEmailMessage::create([
                'business_id' => $request->business->id,
                'location_id' => $location->id,
                'business_email_account_id' => $account->id,
                'contact_id' => $contact->id,
                'direction' => 'outbound',
                'source' => $request->source->value,
                'automation_step_run_id' => $request->automationStepRunId,
                'sent_by_user_id' => $request->sentByUserId,
                'operation_key' => $operationKey,
                'provider' => $account->provider->value,
                'from_email' => (string) $account->mailbox_email,
                'to_email' => $to,
                'subject' => $subject,
                'body_text' => $body,
                'status' => BusinessEmailMessageStatus::Queued->value,
                'attempts' => 0,
            ]);
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent request with the same operation key won the
            // insert; behave exactly like a replay of its row.
            $winner = BusinessEmailMessage::query()
                ->where('business_id', $request->business->id)
                ->where('operation_key', $operationKey)
                ->first();

            if ($winner === null) {
                throw $exception;
            }

            return $winner;
        }
    }

    /**
     * What a repeated call with an already-recorded operation key does. The
     * request has already been proven to be the same logical request
     * (assertSameLogicalRequest), so this only decides whether to retry.
     */
    private function continueExisting(BusinessEmailMessage $message, BusinessEmailSendRequest $request): BusinessEmailMessage
    {
        return match ($message->status) {
            BusinessEmailMessageStatus::Accepted,
            BusinessEmailMessageStatus::Unconfirmed => $message,
            BusinessEmailMessageStatus::Sending => $this->resolveSendingReplay($message),
            BusinessEmailMessageStatus::Queued => $this->attempt($message, $request),
            BusinessEmailMessageStatus::Failed => $this->mayRetry($message)
                ? $this->attempt($message, $request)
                : $message,
        };
    }

    /**
     * A `sending` row inside its lease belongs to a live worker: report it,
     * do nothing. Past the lease it is presumed crashed; because the provider
     * may have accepted it, it becomes `unconfirmed` and is never re-sent.
     */
    private function resolveSendingReplay(BusinessEmailMessage $message): BusinessEmailMessage
    {
        $lease = (int) config('business_email.send.claim_lease_seconds', 300);

        if ($message->claimed_at !== null && $message->claimed_at->gt(now()->subSeconds($lease))) {
            return $message;
        }

        DB::table('business_email_messages')
            ->where('id', $message->id)
            ->where('status', BusinessEmailMessageStatus::Sending->value)
            ->update([
                'status' => BusinessEmailMessageStatus::Unconfirmed->value,
                'updated_at' => now(),
            ]);

        return $message->fresh();
    }

    private function mayRetry(BusinessEmailMessage $message): bool
    {
        return $message->failure_category?->isRetryable() === true
            && $message->attempts < (int) config('business_email.send.max_attempts', 3)
            && ($message->next_attempt_at === null || $message->next_attempt_at->lte(now()));
    }

    /** Claims the row and makes the provider call. */
    private function attempt(BusinessEmailMessage $message, BusinessEmailSendRequest $request): BusinessEmailMessage
    {
        $location = $message->location_id !== null ? BusinessLocation::query()->find($message->location_id) : null;
        $account = $this->senders->defaultFor($request->business, $location);

        if ($account === null) {
            // The account was disconnected between creation and (re)attempt.
            $this->finalizeFailure($message, BusinessEmailFailureCategory::DisconnectedAccount, 'account_not_active', false, expectedStatuses: [
                BusinessEmailMessageStatus::Queued->value,
                BusinessEmailMessageStatus::Failed->value,
            ]);

            return $message->fresh();
        }

        // Claim: exactly one worker moves queued/failed → sending.
        $claimed = DB::table('business_email_messages')
            ->where('id', $message->id)
            ->whereIn('status', [BusinessEmailMessageStatus::Queued->value, BusinessEmailMessageStatus::Failed->value])
            ->update([
                'status' => BusinessEmailMessageStatus::Sending->value,
                'claimed_at' => now(),
                'attempts' => DB::raw('attempts + 1'),
                'business_email_account_id' => $account->id,
                'provider' => $account->provider->value,
                'from_email' => (string) $account->mailbox_email,
                'failure_category' => null,
                'failure_provider_code' => null,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return $message->fresh();
        }

        $message = $message->fresh();

        try {
            // Outside any transaction: a real outbound HTTP request.
            $accessToken = $this->accounts->accessTokenFor($account);

            $result = $this->providers->for($account->provider)->send($accessToken, new BusinessEmailOutbound(
                fromEmail: (string) $account->mailbox_email,
                fromName: $account->display_name,
                toEmail: (string) $message->to_email,
                subject: (string) $message->subject,
                bodyText: (string) $message->body_text,
            ));
        } catch (BusinessEmailProviderException $exception) {
            $this->finalizeFailure(
                $message,
                $exception->category,
                $exception->providerCode,
                $exception->isAmbiguous(),
                expectedStatuses: [BusinessEmailMessageStatus::Sending->value],
            );

            return $message->fresh();
        }

        DB::table('business_email_messages')
            ->where('id', $message->id)
            ->where('status', BusinessEmailMessageStatus::Sending->value)
            ->update([
                'status' => BusinessEmailMessageStatus::Accepted->value,
                'provider_message_id' => $result->providerMessageId,
                'provider_thread_id' => $result->providerThreadId,
                'internet_message_id' => $result->internetMessageId,
                'accepted_at' => now(),
                'claimed_at' => null,
                'updated_at' => now(),
            ]);

        return $message->fresh();
    }

    /**
     * @param list<string> $expectedStatuses the statuses this write may leave
     */
    private function finalizeFailure(
        BusinessEmailMessage $message,
        BusinessEmailFailureCategory $category,
        ?string $providerCode,
        bool $ambiguous,
        array $expectedStatuses,
    ): void {
        $fresh = $message->fresh();
        $attempts = (int) ($fresh?->attempts ?? $message->attempts);
        $maxAttempts = (int) config('business_email.send.max_attempts', 3);

        $status = $ambiguous ? BusinessEmailMessageStatus::Unconfirmed : BusinessEmailMessageStatus::Failed;

        $nextAttemptAt = null;

        if (! $ambiguous && $category->isRetryable() && $attempts < $maxAttempts) {
            $backoff = (array) config('business_email.send.backoff_seconds', [60, 300]);
            $seconds = (int) ($backoff[min($attempts - 1, count($backoff) - 1)] ?? 300);
            $nextAttemptAt = now()->addSeconds(max(1, $seconds));
        }

        DB::table('business_email_messages')
            ->where('id', $message->id)
            ->whereIn('status', $expectedStatuses)
            ->update([
                'status' => $status->value,
                'failure_category' => $category->value,
                'failure_provider_code' => $providerCode !== null ? substr($providerCode, 0, 64) : null,
                'next_attempt_at' => $nextAttemptAt,
                'claimed_at' => null,
                'updated_at' => now(),
            ]);

        // Operator diagnostics: ids and a sanitized code only — never a token,
        // a recipient, a subject or a body.
        Log::warning('Business email send did not succeed.', [
            'message_uid' => $message->uid,
            'business_id' => $message->business_id,
            'provider' => $message->provider instanceof \BackedEnum ? $message->provider->value : $message->provider,
            'category' => $category->value,
            'provider_code' => $providerCode,
            'ambiguous' => $ambiguous,
            'attempts' => $attempts,
        ]);
    }
}
