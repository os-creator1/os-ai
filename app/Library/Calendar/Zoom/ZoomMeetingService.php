<?php

namespace App\Library\Calendar\Zoom;

use App\Enums\Calendar\AppointmentStatus;
use App\Library\Calendar\Notifications\AppointmentNotificationScheduler;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\ZoomConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles ONE appointment with its Zoom meeting. It is the only code that calls the Zoom meeting API, always from a queued
 * job, never from inside a booking transaction or lock.
 *
 * IDEMPOTENT BY CONSTRUCTION. It always reads the appointment's CURRENT state and drives Zoom toward it, so a duplicated, late
 * or out-of-order job cannot restore an old time or create a second meeting:
 *   - no meeting yet + scheduled          -> create once (the meeting id is saved with a conditional UPDATE; a lost race deletes
 *                                            the extra meeting it just made);
 *   - meeting exists + start/length moved -> PATCH the SAME meeting (the join link is preserved);
 *   - appointment not scheduled           -> DELETE the meeting (an already-missing meeting counts as deleted).
 * A per-appointment lock serializes concurrent jobs; it is a cache lock, not a database lock.
 *
 * Only `id` and the participant `join_url` are ever stored. The privileged host start_url never leaves ZoomClient.
 */
class ZoomMeetingService
{
    public const MAX_PASSES = 3;

    public function __construct(
        private readonly ZoomClient $zoom,
        private readonly ZoomConnectionManager $connections,
        private readonly AppointmentNotificationScheduler $notifications,
    ) {
    }

    /**
     * @throws ZoomApiException on a classified provider failure (the job decides whether to retry)
     */
    public function reconcile(int $appointmentId): void
    {
        $lock = Cache::lock('zoom-appt:'.$appointmentId, 120);

        if (! $lock->get()) {
            // Another worker is on it; that pass reads current state, so this one has nothing to add.
            throw new ZoomApiException('transient', 'locked');
        }

        try {
            for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
                if (! $this->pass($appointmentId)) {
                    return;
                }
            }
        } finally {
            $lock->release();
        }
    }

    /** One reconcile step. Returns true when the appointment moved underneath us and another pass is needed. */
    private function pass(int $appointmentId): bool
    {
        $appointment = Appointment::query()->find($appointmentId);

        if ($appointment === null || $appointment->meeting_location_type !== 'zoom') {
            return false;
        }

        if ($appointment->status !== AppointmentStatus::Scheduled) {
            $this->cancelMeeting($appointment);

            return false;
        }

        $host = ZoomHostResolver::hostForAppointment($appointment);

        if ($host === null || ! $host->isActive()) {
            $this->markFailed($appointment, 'host_unavailable');

            throw new ZoomApiException('auth', 'host_unavailable');
        }

        $start = $appointment->start_at->copy()->utc();
        $duration = max(1, (int) $start->diffInMinutes($appointment->end_at->copy()->utc()));

        $this->call(function () use ($appointment, $host, $start, $duration): void {
            $token = $this->connections->accessToken($host);

            if (blank($appointment->zoom_meeting_id)) {
                $this->createMeeting($appointment, $host, $token, $start, $duration);
            } elseif (! $this->inSync($appointment, $start, $duration)) {
                $this->zoom->updateMeeting($token, (string) $appointment->zoom_meeting_id, $this->payload($appointment, $start, $duration));
                $this->markSynced($appointment->id, $start, $duration);
            }
        }, $appointment);

        // The appointment may have been moved or cancelled while we were talking to Zoom: look again.
        $after = Appointment::query()->find($appointmentId);

        if ($after === null) {
            return false;
        }

        $moved = $after->status !== AppointmentStatus::Scheduled
            || ! $this->inSync($after, $after->start_at->copy()->utc(), max(1, (int) $after->start_at->copy()->utc()->diffInMinutes($after->end_at->copy()->utc())));

        if (! $moved) {
            $this->notifications->onZoomReady((int) $after->id);
        }

        return $moved;
    }

    private const LEASE_SECONDS = 120;

    /**
     * Create the meeting once, recoverably.
     *
     * Zoom's create call has no idempotency key, so a lost response (timeout, 5xx, unusable body) leaves the outcome UNKNOWN.
     * Durable state on the appointment makes that safe:
     *   - zoom_create_lease_until: a database lease (not a cache lock) so only one worker creates at a time, across processes;
     *     a crashed worker's lease just expires;
     *   - zoom_create_started_at: set before the call and kept until the outcome is known. While it is set, the next attempt
     *     first LISTS the host's scheduled meetings and adopts ours instead of creating another. If the list cannot be read,
     *     we hold (never blindly re-create).
     * Unavoidable limit: Zoom may list a just-created meeting late; see docs/product/ZOOM-MEETINGS.md.
     */
    private function createMeeting(Appointment $appointment, ZoomConnection $host, string $token, Carbon $start, int $duration): void
    {
        $claim = $this->claimCreate((int) $appointment->id);

        if ($claim === null) {
            throw new ZoomApiException('transient', 'locked');
        }

        [$mine, $uncertain] = $claim;
        $payload = $this->payload($appointment, $start, $duration);
        $outcomeKnown = false;

        try {
            if ($uncertain) {
                try {
                    $found = $this->zoom->findScheduledMeeting($token, $payload);
                } catch (ZoomApiException $e) {
                    if ($e->retryable() || $e->kind === 'auth') {
                        throw $e;
                    }

                    // Cannot read the list (scope missing/denied): the earlier outcome is unverifiable. Hold; do not re-create.
                    throw new ZoomApiException('permanent', 'create_unverifiable');
                }

                if ($found !== null) {
                    $this->saveCreated($appointment, $host, $token, $found, $start, $duration, $mine);
                    $outcomeKnown = true;

                    return;
                }
            }

            try {
                $meeting = $this->zoom->createMeeting($token, $payload);
            } catch (ZoomApiException $e) {
                // Zoom answered with a definite "no": nothing was created. A 5xx or an unusable 2xx may have created one.
                $outcomeKnown = in_array($e->kind, ['auth', 'permanent', 'rate_limited', 'not_found'], true)
                    && $e->errorCode !== 'invalid_meeting_response';

                throw $e;
            }

            $this->saveCreated($appointment, $host, $token, $meeting, $start, $duration, $mine);
            $outcomeKnown = true;
        } finally {
            Appointment::query()->whereKey($appointment->id)->where('zoom_create_token', $mine)->update(array_filter([
                'zoom_create_lease_until' => null,
                'zoom_create_started_at' => $outcomeKnown ? null : false,
            ], fn ($v) => $v !== false));
        }
    }

    /**
     * Take the create lease. Returns [token, outcomeUncertain] or null when a live worker holds it.
     *
     * @return ?array{0: string, 1: bool}
     */
    private function claimCreate(int $appointmentId): ?array
    {
        $before = Appointment::query()->whereKey($appointmentId)->first(['zoom_create_started_at']);
        $uncertain = $before !== null && $before->zoom_create_started_at !== null;
        $token = (string) \Illuminate\Support\Str::uuid();

        $claimed = Appointment::query()->whereKey($appointmentId)->whereNull('zoom_meeting_id')
            ->where(fn ($q) => $q->whereNull('zoom_create_lease_until')->orWhere('zoom_create_lease_until', '<', now()))
            ->update(array_filter([
                'zoom_create_token' => $token,
                'zoom_create_lease_until' => now()->addSeconds(self::LEASE_SECONDS),
                'zoom_create_started_at' => $uncertain ? false : now(),
            ], fn ($v) => $v !== false));

        // A worker that died mid-create left started_at set (its lease expired): the outcome is unknown, so we reconcile first.
        return $claimed === 1 ? [$token, $uncertain] : null;
    }

    /** Save the meeting id only if we still hold the create claim; otherwise a duplicate exists: remove OUR copy. */
    private function saveCreated(Appointment $appointment, ZoomConnection $host, string $token, array $meeting, Carbon $start, int $duration, string $mine): void
    {
        $saved = Appointment::query()->whereKey($appointment->id)->whereNull('zoom_meeting_id')->where('zoom_create_token', $mine)->update([
            'zoom_connection_id' => $host->id,
            'zoom_meeting_id' => $meeting['id'],
            'zoom_join_url' => $meeting['join_url'],
            'zoom_synced_start_at' => $start,
            'zoom_synced_duration' => $duration,
            'zoom_status' => 'ready',
            'zoom_last_error' => null,
            'zoom_create_started_at' => null,
            'updated_at' => now(),
        ]);

        if ($saved !== 1) {
            try {
                $this->zoom->deleteMeeting($token, $meeting['id']);
            } catch (ZoomApiException) {
                // Best effort: the extra meeting was never shared with anyone.
            }
        }
    }

    private function cancelMeeting(Appointment $appointment): void
    {
        if (blank($appointment->zoom_meeting_id)) {
            $this->cancelUncertainCreate($appointment);

            return;
        }

        $host = ZoomHostResolver::hostForAppointment($appointment);

        if ($host === null || ! $host->isActive()) {
            // We can no longer reach the meeting's owner account. Say so plainly instead of pretending it was cancelled.
            $this->markFailed($appointment, 'cancel_host_disconnected');

            return;
        }

        $this->call(function () use ($appointment, $host): void {
            $this->zoom->deleteMeeting($this->connections->accessToken($host), (string) $appointment->zoom_meeting_id);
            Appointment::query()->whereKey($appointment->id)->update(['zoom_status' => 'cancelled', 'zoom_last_error' => null, 'updated_at' => now()]);
        }, $appointment);
    }

    /**
     * Cancelled while a create may have reached Zoom: find and delete that meeting so no orphan stays on the host's calendar.
     * Waits (retry) while a live worker still owns the create; it will see the cancellation on its next pass.
     */
    private function cancelUncertainCreate(Appointment $appointment): void
    {
        $row = Appointment::query()->find($appointment->id);

        if ($row !== null && $row->zoom_create_lease_until !== null && $row->zoom_create_lease_until->isFuture()) {
            throw new ZoomApiException('transient', 'locked');
        }

        if ($row !== null && $row->zoom_create_started_at !== null) {
            $host = ZoomHostResolver::hostForAppointment($row);

            if ($host !== null && $host->isActive()) {
                $this->call(function () use ($row, $host): void {
                    $token = $this->connections->accessToken($host);
                    $start = $row->start_at->copy()->utc();
                    $found = $this->zoom->findScheduledMeeting($token, $this->payload($row, $start, 1));

                    if ($found !== null) {
                        $this->zoom->deleteMeeting($token, $found['id']);
                    }
                }, $row);
            }
        }

        Appointment::query()->whereKey($appointment->id)->update([
            'zoom_status' => 'cancelled', 'zoom_create_started_at' => null, 'zoom_create_lease_until' => null, 'updated_at' => now(),
        ]);
    }

    /** Translate transport errors; record the outcome on the appointment so it is never silent. */
    private function call(callable $work, Appointment $appointment): void
    {
        try {
            $work();
        } catch (ConnectionException) {
            $this->recordError($appointment, 'connection');

            throw new ZoomApiException('transient', 'connection');
        } catch (ZoomApiException $e) {
            $this->recordError($appointment, $e->errorCode);

            throw $e;
        }
    }

    /** Called by the job once retries are exhausted or the error is not retryable. */
    public function markFailed(Appointment|int $appointment, string $code): void
    {
        $id = $appointment instanceof Appointment ? $appointment->id : $appointment;

        Appointment::query()->whereKey($id)->whereIn('zoom_status', ['pending', 'ready', 'failed'])->update([
            'zoom_status' => 'failed', 'zoom_last_error' => mb_substr($code, 0, 64), 'updated_at' => now(),
        ]);
    }

    private function recordError(Appointment $appointment, string $code): void
    {
        Appointment::query()->whereKey($appointment->id)->update([
            'zoom_attempts' => DB::raw('zoom_attempts + 1'), 'zoom_last_error' => mb_substr($code, 0, 64), 'updated_at' => now(),
        ]);
    }

    /** Owner/staff retry after a failure or a reconnect: back to pending with a fresh attempt budget. */
    public function resetForRetry(int $appointmentId): bool
    {
        return Appointment::query()->whereKey($appointmentId)->where('meeting_location_type', 'zoom')
            ->where('status', AppointmentStatus::Scheduled->value)->whereIn('zoom_status', ['failed', 'pending'])
            ->update(['zoom_status' => 'pending', 'zoom_attempts' => 0, 'zoom_last_error' => null, 'updated_at' => now()]) === 1;
    }

    private function inSync(Appointment $appointment, Carbon $start, int $duration): bool
    {
        return $appointment->zoom_synced_start_at !== null
            && $appointment->zoom_synced_start_at->copy()->utc()->equalTo($start)
            && (int) $appointment->zoom_synced_duration === $duration;
    }

    private function markSynced(int $appointmentId, Carbon $start, int $duration): void
    {
        Appointment::query()->whereKey($appointmentId)->update([
            'zoom_synced_start_at' => $start, 'zoom_synced_duration' => $duration, 'zoom_status' => 'ready', 'zoom_last_error' => null, 'updated_at' => now(),
        ]);
    }

    /** @return array{topic: string, start_utc: Carbon, duration: int, timezone: string, agenda: string} */
    private function payload(Appointment $appointment, Carbon $start, int $duration): array
    {
        $type = BookingType::query()->find($appointment->booking_type_id);
        $business = $type === null ? null : ZoomHostResolver::businessOf($type);
        $zone = (string) ($business->timezone ?? 'UTC');

        try {
            new \DateTimeZone($zone);
        } catch (\Exception) {
            $zone = 'UTC';
        }

        return [
            'topic' => trim(($type->name ?? 'Meeting').' with '.($business->name ?? '')),
            'start_utc' => $start,
            'duration' => $duration,
            'timezone' => $zone,
            'agenda' => 'MotionGrove appointment '.$appointment->uid,
        ];
    }
}
