<?php

namespace App\Listeners\Mobile;

use App\Events\Conversation\InboundMessageReceived;
use App\Events\Mobile\MobileConversationChanged;
use App\Jobs\Mobile\SendMobilePush;
use App\Library\Mobile\MobileConversationNotifier;
use App\Models\ChatBox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phone live-update + push for a new inbound SMS. Hangs off the existing, authoritative InboundMessageReceived domain event
 * (emitted once per stored message by BOTH the managed and legacy paths, after commit), so it inherits the platform's own
 * attribution and webhook de-duplication; this is not a second inbound pipeline. Queued: nothing here delays the webhook.
 *
 * The CONVERSATION is the exact one the emitting path filed the message under (carried on the event), verified to belong to the
 * event's Business — never "the latest thread with that phone number", which is wrong for a Business with several numbers.
 * The live event goes out here; each user's push is its own queued, retryable SendMobilePush job keyed by the event's
 * occurrenceKey (the exactly-once key).
 */
class NotifyMobileOfInboundMessage implements ShouldQueue
{
    public function handle(InboundMessageReceived $event): void
    {
        if ($event->conversationBoxId === null) {
            return;
        }

        $box = ChatBox::query()->where('id', $event->conversationBoxId)->where('business_id', $event->businessId)->first();

        if ($box === null) {
            return;
        }

        $users = app(MobileConversationNotifier::class)->changed($box, MobileConversationChanged::RECEIVED);

        // Only an occurrence that just happened is "new": an import, a backfill, or a queue backlog clearing must not ring phones
        // for old messages. Judged by THIS occurrence's own record, never by the thread's latest message (which a newer
        // message would make look fresh for every old occurrence too).
        $freshUntil = $this->freshUntil($event->occurrenceKey);

        if ($freshUntil === null || $freshUntil <= now()->getTimestamp()) {
            return;
        }

        foreach ($users as $user) {
            try {
                SendMobilePush::dispatch((int) $user->id, (int) $box->id, $event->occurrenceKey, $freshUntil);
            } catch (\Throwable $e) {
                // Never let a push problem surface in the inbound pipeline (synchronous queue drivers run the job inline).
                Log::warning('mobile_push_failed', ['exception' => $e::class]);
            }
        }
    }

    /**
     * The unix time until which THIS occurrence is still "new" (its own record's created_at + the freshness window), or null
     * when it cannot be proven fresh (unknown or missing record). The push job re-checks this deadline when it RUNS.
     */
    private function freshUntil(string $occurrenceKey): ?int
    {
        [$kind, $id] = array_pad(explode(':', $occurrenceKey, 2), 2, '');

        $at = match ($kind) {
            'operation' => ctype_digit($id) ? DB::table('business_messaging_operations')->where('id', (int) $id)->value('created_at') : null,
            'report' => ctype_digit($id) ? DB::table('reports')->where('id', (int) $id)->value('created_at') : null,
            default => null,
        };

        if ($at === null) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($at)->getTimestamp() + (int) config('mobile.fresh_message_seconds', 600);
    }
}
