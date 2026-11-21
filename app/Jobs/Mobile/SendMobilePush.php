<?php

namespace App\Jobs\Mobile;

use App\Library\Mobile\MobileRecipients;
use App\Library\Mobile\Push\MobilePushSender;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One user's "new message" push for one inbound occurrence. Its OWN queued, retryable boundary: a provider outage throws
 * out of MobilePushSender (which first releases its per-device claims), so the queue retries with backoff, and the
 * (device, occurrence) UNIQUE claim guarantees a retry can never double-send what already went out. Eligibility is re-checked
 * when the job RUNS, so a session revoked or an account locked while it waited is honoured.
 */
class SendMobilePush implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly int $userId,
        public readonly int $conversationBoxId,
        public readonly string $dedupeKey,
    ) {
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [15, 60, 180, 600];
    }

    public function handle(MobileRecipients $recipients, MobilePushSender $push): void
    {
        $user = User::query()->find($this->userId);
        $box = ChatBox::query()->find($this->conversationBoxId);
        $business = $box === null ? null : Business::query()->find($box->business_id);

        if ($user === null || $box === null || $business === null || ! $recipients->mayReceive($user, $business, $box)) {
            return;
        }

        $push->sendNewMessage($recipients->pushDevicesFor($user), $this->dedupeKey, (string) $business->uid, (string) $box->uid);
    }
}
