<?php

namespace App\Library\BusinessEmail;

use App\DTO\BusinessEmail\BusinessEmailInboundMessage;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Enums\BusinessEmail\BusinessEmailFailureCategory;
use App\Enums\BusinessEmail\BusinessEmailMessageStatus;
use App\Exceptions\BusinessEmail\BusinessEmailProviderException;
use App\Library\BusinessEmail\Contracts\InboundBusinessEmailProvider;
use App\Models\Business;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessEmailMessage;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The ONE inbound synchronizer: reads new inbox mail for one connected
 * mailbox and appends it to the business_email_messages ledger.
 *
 *  - TENANCY. The Business and mailbox come only from the account row; the
 *    sender's Contact is looked up inside that Business; the Location is
 *    always one of that Business's own active Locations.
 *  - IDEMPOTENT. A message is skipped when its provider id is already in the
 *    ledger (which also covers mail we sent), and the insert is guarded by
 *    UNIQUE(business_id, operation_key = `inbound:{id}`), so concurrent or
 *    repeated runs can never store it twice.
 *  - RESUMABLE. The provider cursor is persisted only after the batch is
 *    stored; a crash or temporary failure just re-reads the same window.
 *  - SAFE FAILURE. A dead grant revokes the account (existing manager path);
 *    a missing read permission is recorded as `reauthorization_required` and
 *    never retried; temporary failures are rethrown for the job to retry.
 */
final class BusinessEmailInboundSyncer
{
    public const NEEDS_REAUTHORIZATION = 'reauthorization_required';

    public function __construct(
        private readonly BusinessEmailAccountManager $accounts,
        private readonly BusinessEmailProviderRegistry $providers,
        private readonly BusinessEmailContactResolver $contacts,
    ) {
    }

    /** Does this account's stored grant include the mailbox-read permission? */
    public function canSync(BusinessEmailAccount $account): bool
    {
        if (! $account->isActive()) {
            return false;
        }

        $provider = $this->providers->for($account->provider);

        return $provider instanceof InboundBusinessEmailProvider
            && $provider->capabilities()->inboundSync
            && in_array($provider->inboundScope(), preg_split('/\s+/', (string) $account->granted_scopes) ?: [], true);
    }

    /**
     * @return array{stored: int, skipped: int, has_more: bool}
     * @throws BusinessEmailProviderException on a retryable provider failure
     */
    public function sync(BusinessEmailAccount $account): array
    {
        $result = ['stored' => 0, 'skipped' => 0, 'has_more' => false];

        $account = $account->fresh();

        if ($account === null || ! $account->isActive()) {
            return $result;
        }

        if (! $this->canSync($account)) {
            $this->recordFailure($account, self::NEEDS_REAUTHORIZATION);

            return $result;
        }

        $lock = Cache::lock('business-email-inbound:' . $account->id, 120);

        if (! $lock->get()) {
            return $result;
        }

        try {
            $provider = $this->providers->for($account->provider);
            $token = $this->accounts->accessTokenFor($account);

            try {
                $batch = $provider->fetchInbound(
                    $token,
                    $account->inbound_cursor,
                    (int) config('business_email.inbound.max_messages_per_run', 50),
                );
            } catch (BusinessEmailProviderException $exception) {
                if ($exception->isRevocation()) {
                    $this->accounts->revoke($account);
                } elseif ($exception->category === BusinessEmailFailureCategory::PermissionDenied) {
                    $this->recordFailure($account, self::NEEDS_REAUTHORIZATION);

                    return $result;
                } else {
                    $this->recordFailure($account, $exception->category->value);
                }

                throw $exception;
            }

            $business = Business::query()->find($account->business_id);

            if ($business === null) {
                return $result;
            }

            foreach ($batch->messages as $message) {
                $this->store($business, $account, $message) ? $result['stored']++ : $result['skipped']++;
            }

            $result['has_more'] = $batch->hasMore;

            DB::table('business_email_accounts')
                ->where('id', $account->id)
                ->where('state', 'active')
                ->update([
                    'inbound_cursor' => $batch->cursor !== null ? substr($batch->cursor, 0, 64) : $account->inbound_cursor,
                    'inbound_synced_at' => now(),
                    'inbound_failure' => null,
                    'updated_at' => now(),
                ]);

            return $result;
        } finally {
            $lock->release();
        }
    }

    /** True when a new ledger row was written. */
    private function store(Business $business, BusinessEmailAccount $account, BusinessEmailInboundMessage $message): bool
    {
        $mailbox = strtolower((string) $account->mailbox_email);

        // Mail we sent (SENT label, our own address, or a row we already hold)
        // is never imported as inbound.
        if (in_array('SENT', $message->labels, true)
            || ! in_array('INBOX', $message->labels, true)
            || $message->fromEmail === $mailbox) {
            return false;
        }

        if (BusinessEmailMessage::query()
            ->where('business_id', $business->id)
            ->where('provider_message_id', $message->providerMessageId)
            ->exists()) {
            return false;
        }

        // Reply threading: the earlier message in the same provider thread, or
        // the one this message answers by RFC Message-ID.
        $thread = $this->threadAnchor($business, $message);

        $contactId = $this->contacts->contactIdByAddress($business, $message->fromEmail);

        if ($contactId === null && $thread?->contact_id !== null) {
            $contactId = (int) $thread->contact_id;
        }

        $contact = $contactId !== null
            ? Contacts::query()->where('id', $contactId)->where('business_id', $business->id)->first()
            : null;

        $location = $this->resolveLocation($business, $contact, $thread);

        if ($location === null) {
            Log::warning('Inbound business email skipped: no Location could be proven.', ['business_id' => $business->id]);

            return false;
        }

        try {
            BusinessEmailMessage::create([
                'business_id' => $business->id,
                'location_id' => $location->id,
                'business_email_account_id' => $account->id,
                'contact_id' => $contact?->id,
                'direction' => 'inbound',
                'source' => 'manual',
                'operation_key' => 'inbound:' . $message->providerMessageId,
                'provider' => $account->provider->value,
                'provider_message_id' => $message->providerMessageId,
                'provider_thread_id' => $message->providerThreadId,
                'internet_message_id' => $message->internetMessageId,
                'in_reply_to' => $message->inReplyTo,
                'references_header' => $message->references,
                'from_email' => $message->fromEmail,
                'from_name' => $message->fromName,
                'to_email' => $message->toEmail !== '' ? $message->toEmail : $mailbox,
                'subject' => $message->subject,
                'body_text' => $message->bodyText,
                'status' => BusinessEmailMessageStatus::Received->value,
                'received_at' => $message->receivedAt ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    private function threadAnchor(Business $business, BusinessEmailInboundMessage $message): ?BusinessEmailMessage
    {
        $query = BusinessEmailMessage::query()->where('business_id', $business->id);

        if ($message->providerThreadId !== null) {
            $found = (clone $query)->where('provider_thread_id', $message->providerThreadId)->orderByDesc('id')->first();

            if ($found !== null) {
                return $found;
            }
        }

        if ($message->inReplyTo !== null) {
            return (clone $query)->where('internet_message_id', $message->inReplyTo)->orderByDesc('id')->first();
        }

        return null;
    }

    private function resolveLocation(Business $business, ?Contacts $contact, ?BusinessEmailMessage $thread): ?BusinessLocation
    {
        $candidates = array_filter([
            $contact?->location_id,
            $thread?->location_id,
            Contacts::singleActiveLocationIdFor((int) $business->id),
        ]);

        foreach ($candidates as $id) {
            $location = $this->activeLocation($business, (int) $id);

            if ($location !== null) {
                return $location;
            }
        }

        $primary = BusinessLocation::query()->where('business_id', $business->id)->where('is_primary', true)->first();

        return $primary !== null ? $this->activeLocation($business, (int) $primary->id) : null;
    }

    private function activeLocation(Business $business, int $id): ?BusinessLocation
    {
        $location = BusinessLocation::query()->find($id);

        if ($location === null || (int) $location->business_id !== (int) $business->id) {
            return null;
        }

        $state = $location->lifecycle_state;
        $value = $state instanceof \BackedEnum ? (string) $state->value : (string) $state;

        return $value === BusinessLocationLifecycleState::Active->value ? $location : null;
    }

    private function recordFailure(BusinessEmailAccount $account, string $classification): void
    {
        DB::table('business_email_accounts')
            ->where('id', $account->id)
            ->where('state', 'active')
            ->update(['inbound_failure' => substr($classification, 0, 32), 'updated_at' => now()]);
    }
}
