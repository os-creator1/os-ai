<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\CustomerBaseController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Compatibility only. Email is a channel filter INSIDE Conversations (one unified
 * inbox), not a separate page, so the former `/conversations/email` URL — and the
 * `?thread=` links saved from it — redirect into the inbox's Email filter. Tenancy,
 * permission, entitlement and Location checks all run on the inbox itself; this
 * redirect reads nothing. Email reading and sending live in UnifiedEmailInbox and
 * the Settings → Email endpoints.
 */
class EmailConversationsController extends CustomerBaseController
{
    public function threads(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $thread = substr((string) $request->query('thread', ''), 0, 255);

        // A flash message set by an email send/reply is kept for the page it lands on.
        $request->session()->reflash();

        return redirect()->route('customer.workspaces.businesses.conversations.index', array_filter([
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'channel' => 'email',
            'thread' => $thread,
        ], fn ($value) => $value !== ''));
    }
}
