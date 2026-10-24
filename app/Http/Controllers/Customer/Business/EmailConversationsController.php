<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\BusinessEmail\BusinessEmailAccountManager;
use App\Library\BusinessEmail\BusinessEmailLocationScope;
use App\Models\BusinessEmailMessage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Conversations → Email: the connected mailbox's threads, read from the email
 * ledger (business_email_messages). Presentation only — email is NOT stored in
 * the phone-number-based chat_boxes, and replies go through the existing
 * Settings → Email reply endpoint. Same gates as the SMS inbox: Business
 * tenancy, `chat_box` permission, the `conversations` entitlement, and the
 * actor's Location reach (BusinessEmailLocationScope).
 */
class EmailConversationsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    private const MAX_MESSAGES = 300;

    public function __construct(
        private readonly BusinessEmailLocationScope $locations,
        private readonly BusinessEmailAccountManager $accounts,
    ) {
    }

    public function index(Request $request, string $workspaceUid, string $businessUid): View
    {
        $this->authorize('chat_box');
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Conversations->value);

        $visible = function () use ($business) {
            $query = BusinessEmailMessage::query()->where('business_id', $business->id);
            $this->locations->restrictMessages($query, (int) Auth::id(), $business);

            return $query;
        };

        $columns = ['id', 'uid', 'direction', 'contact_id', 'location_id', 'provider_thread_id', 'from_email', 'from_name', 'to_email', 'subject', 'body_text', 'status', 'received_at', 'created_at'];

        $recent = $visible()->orderByDesc('id')->limit(self::MAX_MESSAGES)->get($columns);

        $threads = $recent
            ->groupBy(fn (BusinessEmailMessage $message) => $this->threadKey($message))
            ->map(function ($messages, $key) {
                $latest = $messages->first();

                return [
                    'key' => (string) $key,
                    'subject' => (string) $latest->subject,
                    'latest' => $latest,
                    'count' => $messages->count(),
                    'counterpart' => $latest->direction === 'inbound' ? $latest->from_email : $latest->to_email,
                ];
            })
            ->values();

        $selectedKey = (string) $request->query('thread', '');
        $selected = null;

        if ($selectedKey !== '') {
            $messages = $visible()
                ->where(fn ($q) => $q->where('provider_thread_id', $selectedKey)->orWhere('uid', $selectedKey))
                ->orderBy('id')
                ->limit(100)
                ->get($columns);

            if ($messages->isNotEmpty()) {
                $target = $messages->reverse()->first(fn ($m) => $m->direction === 'inbound' && $m->status->value !== 'failed')
                    ?? $messages->reverse()->first(fn ($m) => $m->status->value !== 'failed');

                $accessible = $this->locations->accessibleLocationIds((int) Auth::id(), $business);
                $choices = $this->locations->activeLocations($business, $accessible);

                $selected = [
                    'key' => $selectedKey,
                    'messages' => $messages,
                    'reply_to' => $target,
                    // Only needed when this thread's Location is unresolved and there is a real choice.
                    'location_choices' => ($target !== null && $target->location_id === null && $choices['activeTotal'] > 1) ? $choices['selectable'] : collect(),
                ];
            }
        }

        $account = $this->accounts->findForBusiness($business);

        return view('customer.ChatBox.email', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'threads' => $threads,
            'selected' => $selected,
            'canReply' => Gate::allows('chat_box') && $account?->isActive() === true,
            'sendToken' => (string) Str::uuid(),
        ]);
    }

    private function threadKey(BusinessEmailMessage $message): string
    {
        return $message->provider_thread_id !== null && $message->provider_thread_id !== ''
            ? (string) $message->provider_thread_id
            : (string) $message->uid;
    }
}
