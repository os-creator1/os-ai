<?php

namespace App\Console\Commands;

use App\Enums\Calendar\AppointmentStatus;
use App\Jobs\Calendar\SyncZoomMeeting;
use App\Models\Appointment;
use Illuminate\Console\Command;

/**
 * Safety net for Zoom provisioning: re-queues the (idempotent) reconcile job for upcoming Zoom appointments that are still
 * pending after a quiet period (a lost queue job, a worker crash) and for scheduled appointments whose Zoom time is out of
 * step. It never retries `failed` appointments (that needs a person: reconnect Zoom, then "Retry Zoom"), and it is bounded.
 */
class ReconcileZoomMeetings extends Command
{
    protected $signature = 'calendar:reconcile-zoom {--limit=100}';

    protected $description = 'Re-queue stuck Zoom meeting provisioning for upcoming appointments';

    public function handle(): int
    {
        $ids = Appointment::query()
            ->where('meeting_location_type', 'zoom')
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('start_at', '>', now())
            ->where('zoom_status', 'pending')
            ->where('updated_at', '<', now()->subMinutes(10))
            ->orderBy('id')->limit((int) $this->option('limit'))->pluck('id');

        foreach ($ids as $id) {
            // Touch first so two overlapping sweeps do not both queue the same appointment.
            Appointment::query()->whereKey($id)->update(['updated_at' => now()]);
            SyncZoomMeeting::dispatch((int) $id);
        }

        $this->info($ids->count().' queued.');

        return self::SUCCESS;
    }
}
