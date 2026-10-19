<?php

namespace App\Jobs\Calendar;

use App\Jobs\Base;
use App\Library\Calendar\Zoom\ZoomApiException;
use App\Library\Calendar\Zoom\ZoomMeetingService;
use App\Models\Appointment;
use App\Notifications\Calendar\ZoomMeetingFailedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Creates, moves or cancels the Zoom meeting of ONE appointment by reconciling it with the appointment's current state.
 * Dispatched after the booking transaction has committed (from the lifecycle listener), so it never runs under a booking lock.
 *
 * Retries are bounded (config zoom.max_attempts with config zoom.backoff_seconds). Rate limits wait for Zoom's Retry-After;
 * a revoked authorization or any non-retryable error fails at once and is reported, never retried forever.
 */
class SyncZoomMeeting extends Base
{
    public function __construct(public readonly int $appointmentId)
    {
        $this->tries = (int) config('zoom.max_attempts', 6);
        $this->maxExceptions = $this->tries;
        $this->failOnTimeout = false;
    }

    public function handle(ZoomMeetingService $service): void
    {
        try {
            $service->reconcile($this->appointmentId);
        } catch (ZoomApiException $e) {
            if (! $e->retryable()) {
                $this->giveUp($service, $e->errorCode);

                return;
            }

            if ($this->attempts() >= $this->tries) {
                $this->giveUp($service, $e->errorCode);

                return;
            }

            $backoff = (array) config('zoom.backoff_seconds', [30, 120, 600, 1800, 3600]);
            $wait = $e->retryAfter ?? ($backoff[min($this->attempts() - 1, count($backoff) - 1)] ?? 600);

            $this->release($wait);
        }
    }

    public function failed(Throwable $exception): void
    {
        // A crash or timeout: record it so the appointment is never left looking "pending" forever.
        $this->giveUp(app(ZoomMeetingService::class), 'job_failed');
    }

    private function giveUp(ZoomMeetingService $service, string $code): void
    {
        $service->markFailed($this->appointmentId, $code);
        app(\App\Library\Calendar\Notifications\AppointmentNotificationScheduler::class)->onZoomFailed($this->appointmentId);

        Log::error('Zoom meeting provisioning failed.', ['appointment_id' => $this->appointmentId, 'code' => $code]);

        $appointment = Appointment::query()->find($this->appointmentId);
        $host = $appointment === null ? null : \App\Models\User::query()->find($appointment->staff_user_id);

        if ($host !== null && filled($host->email)) {
            Notification::route('mail', $host->email)->notify(new ZoomMeetingFailedNotification((string) $appointment->uid, $code));
        }
    }
}
