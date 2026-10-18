<?php

namespace App\Console\Commands;

use App\Library\Calendar\Notifications\AppointmentNotificationDispatcher;
use Illuminate\Console\Command;

/**
 * Booking Notifications V1 — queues every booking confirmation and reminder that
 * is due. Bounded by --limit; idempotent by the ledger (see the dispatcher), so an
 * overlapping or repeated tick sends nothing twice.
 */
class DispatchDueAppointmentNotifications extends Command
{
    protected $signature = 'calendar:dispatch-due-reminders
        {--limit=200 : Maximum number of due notifications to queue}';

    protected $description = 'Queue due appointment confirmations and reminders';

    public function handle(AppointmentNotificationDispatcher $dispatcher): int
    {
        $limit = $this->option('limit');

        if (! is_numeric($limit) || (int) $limit < 1) {
            $this->error('The --limit option must be a positive integer.');

            return self::INVALID;
        }

        $count = $dispatcher->dispatchDue((int) $limit);

        $this->info("Queued {$count} appointment notification(s).");

        return self::SUCCESS;
    }
}
