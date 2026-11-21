<?php

namespace App\Http\Controllers\Mobile;

use App\Library\Crm\CrmLocationScope;
use App\Library\Mobile\MobileBusinessAccess;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Contacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * READ-ONLY SMS conversations for the phone, over the same rows and the same rules as the web inbox
 * (ChatBoxController::loadChatUsers / messages): `chat_boxes.business_id` equals the selected Business, a
 * Location-limited member sees only reachable Locations, a NULL-business legacy row is never listed.
 * Phase 1 does not send, retry, mark read, pin or delete: those mutate state and have web-side managed-send,
 * opt-out and billing rules that must be reused, not re-implemented (see docs/mobile/ARCHITECTURE.md).
 */
class MobileConversationController extends MobileController
{
    private const PER_PAGE = 30;
    private const MESSAGE_LIMIT = 100;

    public function index(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $resolved = $this->tenant($request, $workspaceUid, $businessUid, MobileBusinessAccess::FEATURE_CONVERSATIONS, 'chat_box');

        if ($resolved === null) {
            return $this->notFound();
        }

        [, $business] = $resolved;
        $user = $this->actor($request);

        $filter = in_array($request->query('filter'), ['unread', 'read'], true) ? $request->query('filter') : 'all';
        $search = trim((string) $request->query('search', ''));
        $page = max(1, (int) $request->query('page', 1));

        $query = ChatBox::query()->where('business_id', $business->id);
        app(CrmLocationScope::class)->restrict($query, $business, (int) $user->id, 'chat_boxes.location_id');

        if ($filter === 'unread') {
            $query->where('notification', '!=', 0);
        } elseif ($filter === 'read') {
            $query->where(fn ($q) => $q->where('notification', 0)->orWhereNull('notification'));
        }

        if ($search !== '') {
            $named = ChatBox::phonesMatchingName($business, $search);
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $query->where(function ($q) use ($like, $named): void {
                $q->where('from', 'LIKE', $like)->orWhere('to', 'LIKE', $like);

                if ($named !== []) {
                    $q->orWhereIn('to', $named);
                }
            });
        }

        $paged = $query->orderByDesc('pinned')->orderByDesc('updated_at')->orderByDesc('id')
            ->with('latestMessage')->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $names = ChatBox::displayNamesFor($business, $paged->getCollection());

        return response()->json([
            'data' => $paged->getCollection()->map(fn (ChatBox $box) => $this->summary($box, $names[$box->id] ?? null))->values(),
            'meta' => ['page' => $paged->currentPage(), 'next_page' => $paged->hasMorePages() ? $paged->currentPage() + 1 : null],
        ])->header('Cache-Control', 'no-store');
    }

    public function show(Request $request, string $workspaceUid, string $businessUid, string $uid): JsonResponse
    {
        $resolved = $this->tenant($request, $workspaceUid, $businessUid, MobileBusinessAccess::FEATURE_CONVERSATIONS, 'chat_box');

        if ($resolved === null) {
            return $this->notFound();
        }

        [, $business] = $resolved;
        $box = $this->box($business, $uid, (int) $this->actor($request)->id);

        if ($box === null) {
            return $this->notFound();
        }

        $limit = min(self::MESSAGE_LIMIT, max(1, (int) $request->query('limit', 50)));
        $before = $request->query('before_id');

        $rows = DB::table('chat_box_messages')->where('box_id', $box->id)
            ->when(is_numeric($before), fn ($q) => $q->where('id', '<', (int) $before))
            ->orderByDesc('id')->limit($limit + 1)
            ->get(['id', 'message', 'media_url', 'sms_type', 'send_by', 'direction', 'created_at']);

        $hasMore = $rows->count() > $limit;
        $messages = $rows->take($limit)->reverse()->values()->map(fn ($m) => [
            'id' => (int) $m->id,
            'direction' => $this->direction($m->direction, $m->send_by),
            'type' => $m->sms_type,
            'body' => $m->message,
            'media_url' => $m->media_url,
            'created_at' => \Carbon\Carbon::parse($m->created_at)->toIso8601String(),
        ]);

        $contact = $box->resolveDisplayContact($business);
        $contact = $contact !== null && $this->contactReachable($contact, (int) $this->actor($request)->id) ? $contact : null;
        $names = ChatBox::displayNamesFor($business, [$box]);

        return response()->json(['data' => [
            'conversation' => $this->summary($box->load('latestMessage'), $names[$box->id] ?? null),
            'contact_uid' => $contact?->uid,
            'messages' => $messages,
            'has_more' => $hasMore,
        ]])->header('Cache-Control', 'no-store');
    }

    /** By public uid AND Business, then the conversation's Location rule: the same chain as the web inbox. */
    private function box(Business $business, string $uid, int $userId): ?ChatBox
    {
        $box = ChatBox::query()->where('uid', $uid)->where('business_id', $business->id)->first();

        if ($box === null) {
            return null;
        }

        if ($box->location_id !== null) {
            $location = $box->location;

            if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation($userId, $location)) {
                return null;
            }
        }

        return $box;
    }

    private function contactReachable(Contacts $contact, int $userId): bool
    {
        if ($contact->location_id === null) {
            return true;
        }

        $location = $contact->location;

        return $location !== null && app(LocationAccessGuard::class)->userCanAccessLocation($userId, $location);
    }

    /** @return array<string, mixed> */
    private function summary(ChatBox $box, ?string $name): array
    {
        $last = $box->latestMessage;

        return [
            'uid' => $box->uid,
            'display_name' => $name,
            'phone' => $box->to !== null ? '+' . ltrim((string) $box->to, '+') : null,
            'unread' => (int) $box->notification > 0,
            'pinned' => (bool) $box->pinned,
            'last_message' => $last === null ? null : [
                'direction' => $this->direction($last->direction, $last->send_by),
                'preview' => mb_substr((string) $last->message, 0, 140),
                'at' => $last->created_at?->toIso8601String(),
            ],
            'updated_at' => $box->updated_at?->toIso8601String(),
        ];
    }

    private function direction(?string $direction, ?string $sendBy): string
    {
        if (in_array($direction, ['incoming', 'in'], true) || ($direction === null && $sendBy === 'to')) {
            return 'inbound';
        }

        return 'outbound';
    }
}
