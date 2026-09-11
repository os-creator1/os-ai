<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

class PusherController extends Controller
{
    /**
     * POST /pusher/auth (`pusher.auth`) — the compatibility endpoint for
     * Pusher JS clients authorizing a private channel.
     *
     * Customer Experience Redesign Slice 2B: this used to sign WHATEVER
     * channel name a logged-in user sent, with the Pusher credentials read
     * straight from the environment, and never consulted routes/channels.php
     * — so any customer could join any other Business's
     * `chat.business.{businessUid}` channel through it.
     *
     * It now does exactly what Laravel's own /broadcasting/auth does
     * (Illuminate\Broadcasting\BroadcastController::authenticate): hand the
     * request to Broadcast::auth(), which runs the channel callbacks in
     * routes/channels.php. Those callbacks are the only authorization; this
     * controller has none of its own, so the two endpoints cannot disagree.
     */
    public function pusherAuth(Request $request)
    {
        if ($request->hasSession()) {
            $request->session()->reflash();
        }

        return Broadcast::auth($request);
    }
}
