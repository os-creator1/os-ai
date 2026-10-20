<?php

namespace App\Library\Voice;

use App\Enums\Voice\VoiceCallStatus;
use App\Library\Contacts\ContactDirectory;
use App\Library\Messaging\E164Normalizer;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use App\Models\User;
use App\Models\VoiceCall;
use App\Models\VoiceNumber;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * The canonical call service: creates the record for a call a user is about to place, accepts the
 * browser's (non-authoritative) hints, stores notes, and answers history queries. Final outcomes are
 * written only by VoiceCallReconciler from verified provider events.
 */
final class VoiceCallService
{
    /** An initiating call with no provider evidence this old can no longer be "in flight". */
    public const STALE_INITIATING_SECONDS = 120;

    public function __construct(
        private readonly VoiceNumberResolver $numbers,
        private readonly ContactDirectory $directory,
        private readonly LocationAccessGuard $locations,
    ) {
    }

    /**
     * Resolve who is being called from a contact or a conversation of THIS Business that the user may
     * reach. The destination always comes from the stored record, never from the request.
     *
     * @return array{contact: ?Contacts, number: string, location_id: ?int, name: ?string}
     */
    public function resolveTarget(User $user, Business $business, ?string $contactUid, ?string $conversationUid): array
    {
        $contact = null;
        $locationId = null;
        $digits = null;

        if ($contactUid !== null && $contactUid !== '') {
            $contact = $this->directory->findForBusiness($business, $contactUid, (int) $user->id);

            if ($contact === null) {
                throw new VoiceUnavailableException('contact_not_found', 'That contact was not found.', 404);
            }

            $digits = (string) $contact->phone;
            $locationId = $contact->location_id !== null ? (int) $contact->location_id : null;
        } elseif ($conversationUid !== null && $conversationUid !== '') {
            $box = ChatBox::query()->where('uid', $conversationUid)->where('business_id', (int) $business->id)->first();

            if ($box === null || ($box->location_id !== null && ! $this->locations->userCanAccessLocation((int) $user->id, $box->location()->firstOrFail()))) {
                throw new VoiceUnavailableException('conversation_not_found', 'That conversation was not found.', 404);
            }

            $contact = $box->resolveDisplayContact($business);
            $digits = (string) $box->to;
            $locationId = $box->location_id !== null ? (int) $box->location_id : null;
        } else {
            throw new VoiceUnavailableException('target_required', 'Choose a contact or conversation to call.', 422);
        }

        $number = E164Normalizer::normalize($digits);

        if ($number === null) {
            throw new VoiceUnavailableException('invalid_phone', 'This contact does not have a valid phone number that can be called.');
        }

        $name = $contact !== null ? ($this->directory->profile($business, $contact, false)['name'] ?? null) : null;

        return ['contact' => $contact, 'number' => $number, 'location_id' => $locationId, 'name' => $name];
    }

    /**
     * Record an outgoing call the user is about to place. Idempotent on the client's key (a double
     * click returns the same call) and refuses a second overlapping call by the same user.
     *
     * @return array{call: VoiceCall, created: bool}
     */
    public function startOutbound(User $user, Workspace $workspace, Business $business, VoiceNumber $from, array $target, string $idempotencyKey): array
    {
        $lock = Cache::lock('voice:start:' . (int) $user->id, 10);

        if (! $lock->get()) {
            throw new VoiceUnavailableException('call_in_progress', 'A call is already being started. Wait a moment.', 409);
        }

        try {
            $uid = Uuid::uuid5(Uuid::NAMESPACE_URL, hash_hmac('sha256', $user->id . '|' . $idempotencyKey, (string) config('app.key')))->toString();

            $existing = VoiceCall::query()->where('uid', $uid)->where('user_id', (int) $user->id)->first();

            if ($existing !== null) {
                return ['call' => $existing, 'created' => false];
            }

            $this->expireStaleInFlight((int) $user->id);

            if (VoiceCall::query()->where('user_id', (int) $user->id)->whereIn('status', [VoiceCallStatus::Initiating->value, VoiceCallStatus::Ringing->value, VoiceCallStatus::Answered->value])->exists()) {
                throw new VoiceUnavailableException('call_in_progress', 'You already have a call in progress. End it before starting another.', 409);
            }

            try {
                $call = VoiceCall::query()->create([
                    'uid' => $uid,
                    'business_id' => (int) $business->id,
                    'workspace_id' => (int) $workspace->id,
                    'location_id' => $target['location_id'],
                    'contact_id' => $target['contact']?->id,
                    'voice_number_id' => (int) $from->id,
                    'user_id' => (int) $user->id,
                    'direction' => VoiceCall::DIRECTION_OUTBOUND,
                    'from_number' => $from->phone_number,
                    'to_number' => $target['number'],
                    'counterpart_number' => $target['number'],
                    'status' => VoiceCallStatus::Initiating->value,
                    'status_rank' => VoiceCallStatus::Initiating->rank(),
                    'initiated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return ['call' => VoiceCall::query()->where('uid', $uid)->firstOrFail(), 'created' => false];
            }

            return ['call' => $call, 'created' => true];
        } finally {
            $lock->release();
        }
    }

    /**
     * What the browser says happened. A hint, never the outcome: the only thing it can decide alone is
     * that a call never reached Telnyx at all (the SDK refused to dial), because then no provider event
     * will ever arrive. Anything else only marks the call "ended, not yet confirmed" until Telnyx says so.
     */
    public function recordClientEvent(VoiceCall $call, string $event, ?string $detail = null): VoiceCall
    {
        // Decided under the row lock against the CURRENT row, so a hint can never overwrite a cancel, a provider
        // outcome or a bridge that was recorded a moment earlier.
        return DB::transaction(function () use ($call, $event, $detail): VoiceCall {
            $row = VoiceCall::query()->whereKey($call->id)->lockForUpdate()->first() ?? $call;
            $current = $row->statusEnum();

            if ($current->isFinal()) {
                return $row;
            }

            if ($event === 'dial_failed' && $row->bridge_requested_at === null && $current === VoiceCallStatus::Initiating) {
                $row->forceFill([
                    'status' => VoiceCallStatus::Failed->value,
                    'status_rank' => VoiceCallStatus::Failed->rank(),
                    'hangup_cause' => 'client_' . Str::limit(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $detail)) ?: 'error', 40, ''),
                    'ended_at' => now(),
                    'duration_seconds' => 0,
                ])->save();
            } elseif ($event === 'ended' && $current->rank() < VoiceCallStatus::Unconfirmed->rank()) {
                $row->forceFill([
                    'status' => VoiceCallStatus::Unconfirmed->value,
                    'status_rank' => VoiceCallStatus::Unconfirmed->rank(),
                    'client_reported_ended_at' => $row->client_reported_ended_at ?? now(),
                    // A browser that hung up before the bridge leaves the server-placed leg to us: it is owed a
                    // hangup (retried by voice:retry-teardowns). After the bridge the browser's own hangup ended it.
                    'leg_teardown_status' => $row->bridge_requested_at === null && $row->provider_call_control_id !== null && $row->leg_teardown_status !== 'done'
                        ? 'pending' : $row->leg_teardown_status,
                ])->save();
            }

            return $row;
        });
    }

    public function saveNote(VoiceCall $call, ?string $note): VoiceCall
    {
        $note = $note === null ? null : trim($note);
        $call->forceFill(['note' => $note === '' ? null : Str::limit($note, 2000, '')])->save();

        return $call;
    }

    /**
     * Calls that can no longer be in flight stop blocking the user. An attempt whose browser leg was never
     * answered within two minutes is closed as failed; one that has been "ringing" or "in progress" far longer
     * than any real call without a provider hangup is marked unconfirmed (never guessed into "completed").
     */
    private function expireStaleInFlight(int $userId): void
    {
        VoiceCall::query()
            ->where('user_id', $userId)
            ->where('status', VoiceCallStatus::Initiating->value)
            ->where('initiated_at', '<', now()->subSeconds(self::STALE_INITIATING_SECONDS))
            ->update([
                'status' => VoiceCallStatus::Failed->value,
                'status_rank' => VoiceCallStatus::Failed->rank(),
                'hangup_cause' => 'never_connected',
                'ended_at' => now(),
                'duration_seconds' => 0,
            ]);

        VoiceCall::query()
            ->where('user_id', $userId)
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('status', VoiceCallStatus::Ringing->value)->where('initiated_at', '<', now()->subMinutes(10)))
                ->orWhere(fn ($r) => $r->where('status', VoiceCallStatus::Answered->value)->where('initiated_at', '<', now()->subHours(6))))
            ->update(['status' => VoiceCallStatus::Unconfirmed->value, 'status_rank' => VoiceCallStatus::Unconfirmed->rank()]);
    }

    /** Resolve an incoming caller to a Contact of THIS Business, or null — never a guess across tenants. */
    public function contactForNumber(Business $business, string $e164): ?Contacts
    {
        $digits = preg_replace('/\D/', '', $e164);

        if ($digits === '') {
            return null;
        }

        $matches = Contacts::query()
            ->where('business_id', (int) $business->id)
            ->where('phone', (int) $digits)
            ->limit(2)
            ->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
