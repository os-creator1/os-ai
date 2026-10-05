<?php

namespace App\Library\Calendar\Notifications;

use App\Jobs\Calendar\SendAppointmentNotification;
use App\Models\AppointmentNotification;
use Illuminate\Support\Carbon;

/**
 * Booking Notifications V1 — the due-work sweep behind calendar:dispatch-due-reminders.
 *
 * It finds pending ledger rows whose moment has come and queues one job each. It
 * decides nothing about whether a row should be sent: the sender re-checks the
 * appointment, the start it was bound to, consent and readiness at send time, and
 * claims the row atomically. So the sweep may run every minute, overlap itself, or
 * re-queue a row whose job was lost — a row can still only be sent once.
 *
 * `dispatched_at` only throttles re-queueing: a row is (re)queued when it has
 * never been queued or its last job is older than REDISPATCH_AFTER_MINUTES, which
 * is how a job lost to a queue outage still gets its one delivery.
 */
class AppointmentNotificationDispatcher
{
    public const REDISPATCH_AFTER_MINUTES = 10;

    public function dispatchDue(int $limit = 200): int
    {
        $now = Carbon::now('UTC');
        $stale = $now->copy()->subMinutes(self::REDISPATCH_AFTER_MINUTES);
        $dispatched = 0;

        $ids = AppointmentNotification::query()
            ->where('status', AppointmentNotification::STATUS_PENDING)
            ->where('scheduled_for', '<=', $now)
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $stale))
            ->orderBy('scheduled_for')
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            // Claim the queueing, not the send: only the sweep that wins this update queues the job.
            $won = AppointmentNotification::query()->whereKey($id)
                ->where('status', AppointmentNotification::STATUS_PENDING)
                ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', $stale))
                ->update(['dispatched_at' => $now]);

            if ($won === 1) {
                SendAppointmentNotification::dispatch((int) $id);
                $dispatched++;
            }
        }

        return $dispatched;
    }
}
