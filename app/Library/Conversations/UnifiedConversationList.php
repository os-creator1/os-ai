<?php

namespace App\Library\Conversations;

use App\Models\Business;
use App\Models\ChatBox;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Composes the unified Conversations list from the two existing read models:
 * the SMS conversations (chat_boxes, already queried and paginated by the
 * caller, so every existing filter, search and Location rule still applies) and
 * the email threads (UnifiedEmailInbox). Presentation only.
 *
 * "All" shows each person once. An email thread whose Contact provably owns an
 * SMS conversation (same Contact, never the same name) is folded into that
 * conversation's row, which then carries the newer of the two activities; every
 * other thread — no Contact, or a Contact with no SMS conversation — is its own
 * Email row. A page holds the activity inside its own SMS window, so rows stay
 * newest-first across pages.
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
        $smsItems = $sms === null ? collect() : $sms->getCollection()->map(fn (ChatBox $chat) => ['type' => 'sms', 'chat' => $chat, 'at' => $chat->updated_at]);

        if (! $emailEligible) {
            return $smsItems->values()->all();
        }

        $threads = $this->inbox->threads($business, $userId, $search);

        if ($channel === 'email') {
            $slice = $threads->slice(max(0, $page - 1) * UnifiedConversationList::PER_PAGE, UnifiedConversationList::PER_PAGE);
            $names = $this->inbox->contactNames($business, $slice->pluck('contact_id')->all());

            return $slice->map(fn (array $thread) => ['type' => 'email', 'thread' => $thread, 'name' => $names[$thread['contact_id']] ?? null, 'at' => $thread['at']])->values()->all();
        }

        // All: this page's SMS window — newest edge is where the previous page ended, oldest is this page's last row.
        $top = $page > 1 ? (clone $smsQuery)->offset((($page - 1) * self::PER_PAGE) - 1)->limit(1)->value('updated_at') : null;
        $bottom = $sms !== null && $sms->hasMorePages() ? $sms->getCollection()->last()?->updated_at : null;
        $inWindow = fn (CarbonInterface $at): bool => ($top === null || $at->lte($top)) && ($bottom === null || $at->gt($bottom));

        $boxes = $this->inbox->boxesByContact($business, $userId, $threads->pluck('contact_id')->all());
        $names = $this->inbox->contactNames($business, $threads->pluck('contact_id')->all());

        $rows = $smsItems->keyBy(fn (array $row) => $row['chat']->id)->all();
        $newestByBox = [];
        $emailRows = [];

        foreach ($threads as $thread) {
            $box = $thread['contact_id'] !== null ? ($boxes[$thread['contact_id']] ?? null) : null;

            if ($box === null) {
                if ($inWindow($thread['at'])) {
                    $emailRows[] = ['type' => 'email', 'thread' => $thread, 'name' => $names[$thread['contact_id']] ?? null, 'at' => $thread['at']];
                }

                continue;
            }

            if ($box->pinned) {
                continue; // the person is already in the pinned rail
            }

            if (! isset($newestByBox[$box->id]) || $thread['at']->gt($newestByBox[$box->id]['at'])) {
                $newestByBox[$box->id] = ['box' => $box, 'at' => $thread['at'], 'preview' => trim(preg_replace('/\s+/', ' ', ($thread['subject'] !== '' ? $thread['subject'] . ' — ' : '') . (string) $thread['latest']->body_text))];
            }
        }

        foreach ($newestByBox as $boxId => $found) {
            $newer = $found['at']->gt($found['box']->updated_at);

            if (isset($rows[$boxId])) {
                $rows[$boxId]['withEmail'] = true;
            } elseif ($newer && $inWindow($found['at'])) {
                $rows[$boxId] = ['type' => 'sms', 'chat' => $found['box'], 'at' => $found['box']->updated_at, 'withEmail' => true];
            } else {
                continue;
            }

            if ($newer) {
                $rows[$boxId]['at'] = $found['at'];
                $rows[$boxId]['emailPreview'] = $found['preview'];
            }
        }

        $merged = array_merge(array_values($rows), $emailRows);
        usort($merged, fn (array $a, array $b): int => $b['at']->getTimestamp() <=> $a['at']->getTimestamp());

        return $merged;
    }
}
