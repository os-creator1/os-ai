<?php

namespace App\Events\Mobile;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * "Something changed in this conversation — re-read it." Deliberately CONTENT-FREE: it carries opaque uids and a change type,
 * never message text, a name or a number, so a leaked or mis-routed event reveals nothing. The phone then calls the normal API,
 * which re-applies tenancy, Location and permission rules.
 *
 * One event per ELIGIBLE USER (not per Business): the recipient set — membership, module visibility, Location reach, lock —
 * is decided server-side at dispatch time, and the channel authorization re-checks it at subscribe time. Queued (ShouldBroadcast)
 * so a slow or unavailable broadcast provider never delays inbound SMS processing.
 */
class MobileConversationChanged implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    public const RECEIVED = 'received';
    public const OUTBOUND = 'outbound';
    public const STATUS = 'status';
    public const READ = 'read';

    public function __construct(
        public readonly string $userUid,
        public readonly string $businessUid,
        public readonly string $conversationUid,
        public readonly string $type,
    ) {
    }

    public static function channelName(string $businessUid, string $userUid): string
    {
        return 'mobile.business.' . $businessUid . '.user.' . $userUid;
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->businessUid, $this->userUid))];
    }

    public function broadcastAs(): string
    {
        return 'conversation.changed';
    }

    /** @return array<string, string> */
    public function broadcastWith(): array
    {
        return ['type' => $this->type, 'business_uid' => $this->businessUid, 'conversation_uid' => $this->conversationUid];
    }
}
