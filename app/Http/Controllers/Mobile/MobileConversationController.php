<?php

namespace App\Http\Controllers\Mobile;

use App\Library\Conversations\ConversationSendFailureReason;
use App\Library\Conversations\ConversationSender;
use App\Library\Conversations\ManagedSendStateMachine;
use App\Library\Messaging\ManagedDispatchDelegate;
use App\Library\Crm\CrmLocationScope;
use App\Library\Mobile\MobileBusinessAccess;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Contacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

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

        // The desktop timeline reconciles crashed/stale 'sending' claims
        // under a row lock before rendering them. Keep mobile's projection
        // consistent so recoverable failures do not remain stuck forever.
        $sendingIds = ChatBoxMessage::query()
            ->where('box_id', $box->id)
            ->where('send_status', ManagedSendStateMachine::SENDING)
            ->pluck('id');

        foreach ($sendingIds as $sendingId) {
            DB::transaction(function () use ($sendingId, $business): void {
                $locked = ChatBoxMessage::query()->whereKey($sendingId)->lockForUpdate()->first();

                if ($locked !== null) {
                    ManagedSendStateMachine::reconcileSendingRow($locked, (int) $business->id);
                }
            });
        }

        $limit = min(self::MESSAGE_LIMIT, max(1, (int) $request->query('limit', 50)));
        $before = $request->query('before_id');

        $rows = DB::table('chat_box_messages')->where('box_id', $box->id)
            ->when(is_numeric($before), fn ($q) => $q->where('id', '<', (int) $before))
            ->orderByDesc('id')->limit($limit + 1)
            ->get(['id', 'message', 'media_url', 'sms_type', 'send_by', 'direction', 'send_uid', 'send_status', 'send_failure_reason', 'created_at']);

        $hasMore = $rows->count() > $limit;
        $messages = $rows->take($limit)->reverse()->values()->map(fn ($m) => $this->presentMessage($m));

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

    /** Marks the conversation read: the SAME `chat_boxes.notification` counter the desktop list and Home read. */
    public function read(Request $request, string $workspaceUid, string $businessUid, string $uid): JsonResponse
    {
        $resolved = $this->tenant($request, $workspaceUid, $businessUid, MobileBusinessAccess::FEATURE_CONVERSATIONS, 'chat_box');
        $box = $resolved === null ? null : $this->box($resolved[1], $uid, (int) $this->actor($request)->id);

        if ($box === null) {
            return $this->notFound();
        }

        // Straight to the column, no model save: reading must not bump updated_at, or it would re-sort the inbox.
        DB::table('chat_boxes')->where('id', $box->id)->where('business_id', $resolved[1]->id)->update(['notification' => 0]);

        return response()->json(['data' => ['uid' => $box->uid, 'unread' => false]])->header('Cache-Control', 'no-store');
    }

    /**
     * Send an SMS reply through ConversationSender — the same path as the desktop CRM (wallet, STOP/blacklist, readiness,
     * managed dispatch, history). `idempotency_key` is the client's UUID for this ONE logical message: a repeat of a
     * key that already sent (or is sending) returns that message's current state and never reaches the provider again.
     */
    public function reply(Request $request, string $workspaceUid, string $businessUid, string $uid): JsonResponse
    {
        $resolved = $this->tenant($request, $workspaceUid, $businessUid, MobileBusinessAccess::FEATURE_CONVERSATIONS, 'chat_box');
        $box = $resolved === null ? null : $this->box($resolved[1], $uid, (int) $this->actor($request)->id);

        if ($box === null) {
            return $this->notFound();
        }

        $v = Validator::make($request->all(), [
            'body' => ['required', 'string', 'max:1600'],
            'idempotency_key' => ['required', 'uuid'],
        ]);
        $body = trim((string) $request->input('body'));

        if ($v->fails() || $body === '') {
            return $this->fail('validation_failed', $v->errors()->first() ?: 'Write a message first.', 422);
        }

        if (config('app.stage') == 'demo') {
            return $this->fail('unavailable', 'Sending is not available in demo mode.', 422);
        }

        // Legacy (non-managed) transport has no persisted conversation
        // idempotency result. Replaying a timed-out request could send and
        // bill twice, so it is unsupported from mobile until it has a
        // durable send ledger. BYO selected channels count as managed here.
        if (! ManagedDispatchDelegate::isManaged((int) $resolved[1]->id)) {
            return $this->fail('messaging_unavailable', 'Mobile replies require a connected managed or BYO messaging sender.', 422);
        }

        $box->setRelation('business', $resolved[1]);
        $key = strtolower((string) $request->input('idempotency_key'));
        $existing = ChatBoxMessage::query()->where('box_id', $box->id)->where('send_uid', $key)->first();

        if ($existing !== null) {
            // A replay never re-sends. Failed => say so (the app offers Retry, a deliberate new attempt).
            return $this->outcome($existing->send_status === null ? null : $existing, $key, null);
        }

        $response = app(ConversationSender::class)->reply($box, $body, null, $key, true);

        return $this->outcome(ChatBoxMessage::query()->where('box_id', $box->id)->where('send_uid', $key)->first(), $key, $response);
    }

    /** A deliberate new attempt for a Failed / Delivery-failed message: same bubble, fresh gates, one provider call. */
    public function retry(Request $request, string $workspaceUid, string $businessUid, string $uid): JsonResponse
    {
        $resolved = $this->tenant($request, $workspaceUid, $businessUid, MobileBusinessAccess::FEATURE_CONVERSATIONS, 'chat_box');
        $box = $resolved === null ? null : $this->box($resolved[1], $uid, (int) $this->actor($request)->id);

        if ($box === null) {
            return $this->notFound();
        }

        $key = strtolower((string) $request->input('send_uid'));

        if (! Str::isUuid($key)) {
            return $this->fail('validation_failed', 'Missing message.', 422);
        }

        $box->setRelation('business', $resolved[1]);
        $response = app(ConversationSender::class)->retry($box, $key);

        if ($response === null) {
            return $this->notFound();
        }

        return $this->outcome(ChatBoxMessage::query()->where('box_id', $box->id)->where('send_uid', $key)->first(), $key, $response);
    }

    /**
     * Maps the desktop-shaped result onto the mobile contract. Success => 200 {data:{message}}; anything else => 422 with a
     * stable `code`, a customer-safe message and whether a Retry is meaningful. Never exposes provider payloads or ids.
     */
    private function outcome(?ChatBoxMessage $bubble, string $key, ?JsonResponse $response): JsonResponse
    {
        $payload = $response?->getData();

        if ($response === null) {
            // A replay of a key we already hold: sending/sent/delivered/ambiguous count as "accepted", failed ones do not.
            $ok = $bubble !== null && (ManagedSendStateMachine::isTerminalSuccess($bubble->send_status)
                || in_array($bubble->send_status, [ManagedSendStateMachine::SENDING, ManagedSendStateMachine::AMBIGUOUS], true));
        } else {
            $ok = ($payload->status ?? null) === 'success';
        }

        if ($ok) {
            // Legacy (non-managed) sending keeps no tracked bubble: report what we know.
            $message = $bubble === null ? ['send_uid' => $key, 'status' => 'sent'] : $this->presentMessage((object) $bubble->getAttributes());

            return response()->json(['data' => ['message' => $message]])->header('Cache-Control', 'no-store');
        }

        $reason = $bubble?->send_failure_reason !== null ? ConversationSendFailureReason::tryFrom((string) $bubble->send_failure_reason) : null;
        $optedOut = ($payload->reason ?? null) === 'opted_out';

        return response()->json(['error' => [
            'code' => $optedOut ? 'opted_out' : ($reason?->value ?? 'send_refused'),
            // The desktop's own refusal text (blacklist, coverage, balance…) is already customer-safe and specific: keep it.
            'message' => ($payload->message ?? null) ?: ($reason?->customerMessage() ?? 'This message could not be sent.'),
            'retryable' => $bubble !== null && ManagedSendStateMachine::isRetryEligible($bubble->send_status),
            'send_uid' => $bubble?->send_uid,
        ]], 422)->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function presentMessage(object $m): array
    {
        $direction = $this->direction($m->direction, $m->send_by);
        $reason = $m->send_failure_reason !== null ? ConversationSendFailureReason::tryFrom((string) $m->send_failure_reason) : null;

        return [
            'id' => (int) $m->id,
            'direction' => $direction,
            'type' => $m->sms_type,
            'body' => $m->message,
            'media_url' => $m->media_url,
            // Only tracked manual sends carry a delivery state (sending|sent|delivered|failed|delivery_failed|ambiguous).
            'send_uid' => $direction === 'outbound' ? $m->send_uid : null,
            'status' => $direction === 'outbound' ? $m->send_status : null,
            'failure_reason' => $reason?->value,
            'status_message' => $reason?->customerMessage(),
            'retryable' => $direction === 'outbound' && ManagedSendStateMachine::isRetryEligible($m->send_status),
            'created_at' => \Carbon\Carbon::parse($m->created_at)->toIso8601String(),
        ];
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
        if (in_array($direction, ['incoming', 'inbound', 'in'], true) || ($direction === null && $sendBy === 'to')) {
            return 'inbound';
        }

        return 'outbound';
    }
}
