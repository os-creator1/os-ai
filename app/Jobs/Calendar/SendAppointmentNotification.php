<?php

namespace App\Jobs\Calendar;

use App\Jobs\Base;
use App\Library\Calendar\Notifications\AppointmentNotificationSender;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Throwable;

/**
 * Booking Notifications V1 — delivers one appointment_notifications row.
 *
 * AFTER COMMIT, STRUCTURALLY (the Documents precedent): a rolled-back transaction
 * dispatches nothing. Runs once ($tries = 1 from Base); the at-most-once guarantee
 * does not rest on that, it rests on the sender's conditional claim of the row, so
 * a duplicate or retried job is a harmless no-op. The job carries only a ledger id:
 * no address, phone number or message ever sits in the queue table.
 */
class SendAppointmentNotification extends Base implements ShouldQueueAfterCommit
{
    public function __construct(private readonly int $notificationId)
    {
    }

    public function handle(AppointmentNotificationSender $sender): void
    {
        $sender->deliver($this->notificationId);
    }

    public function failed(Throwable $exception): void
    {
        // A worker crash / timeout after the claim: the row is still `sending`.
        app(AppointmentNotificationSender::class)->markFailed($this->notificationId);
    }
}
