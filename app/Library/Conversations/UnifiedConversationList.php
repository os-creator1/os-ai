<?php

namespace App\Library\Conversations;

use App\Models\Business;
use App\Models\ChatBox;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Two separate read models: All / SMS show only existing SMS lead conversations,
 * while Email lists all visible threads from the connected mailbox.
 *
 * The opened lead's ContactActivityTimeline still includes linked email, SMS,
 * automation, and CRM activities. Email alone never creates a lead list row.
 */
class UnifiedConversationList
{
    public const PER_PAGE = 50;

    public function __construct(private readonly UnifiedEmailInbox $inbox)
    {
    }

    /**
     * @param  Builder<ChatBox>  $smsQuery  the filtered, ordered SMS query (not yet paginated)
     * @return list<array<string, mixed>>
     */
    public function items(Business $business, int $userId, string $channel, bool $emailEligible, Builder $smsQuery, ?LengthAwarePaginator $sms, int $page, string $search): array
    {
        $page = max(1, $page);
        $smsItems = $sms === null ? collect() : $sms->getCollection()->map(fn (ChatBox $chat) => ['type' => 'sms', 'chat' => $chat, 'at' => $chat->updated_at]);

        if ($channel !== 'email' || ! $emailEligible) {
            return $smsItems->values()->all();
        }

        $threads = $this->inbox->threads($business, $userId, $search);

        if ($channel === 'email') {
            $slice = $threads->slice(max(0, $page - 1) * UnifiedConversationList::PER_PAGE, UnifiedConversationList::PER_PAGE);
            $names = $this->inbox->contactNames($business, $userId, $slice->pluck('contact_id')->all());

            return $slice->map(fn (array $thread) => ['type' => 'email', 'thread' => $thread, 'name' => $names[$thread['contact_id']] ?? null, 'at' => $thread['at']])->values()->all();
        }

    }
}
