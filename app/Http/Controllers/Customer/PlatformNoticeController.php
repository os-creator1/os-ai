<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager;
use App\Library\PlatformAutomation\PlatformNoticeReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** The signed-in customer acting on their OWN Platform notice / banner. Nothing else is reachable here. */
class PlatformNoticeController extends Controller
{
    /** The signed-in customer's live banners and unread notices, as plain text for the shell to render. */
    public function feed(PlatformNoticeReader $notices, PlatformAnnouncementManager $announcements): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'banners' => $announcements->bannersFor($user)->map(fn ($a) => [
                'uid' => $a->uid, 'title' => $a->title, 'body' => $a->body, 'severity' => $a->severity,
            ])->values(),
            'notices' => $notices->unreadFor($user)->map(fn ($n) => [
                'id' => $n->id, 'title' => (string) ($n->data['title'] ?? ''), 'message' => (string) ($n->data['message'] ?? ''),
            ])->values(),
        ]);
    }

    public function read(Request $request, string $notice, PlatformNoticeReader $notices): RedirectResponse|JsonResponse
    {
        $notices->markRead(Auth::user(), $notice);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }

    public function dismiss(Request $request, string $announcement, PlatformAnnouncementManager $announcements): RedirectResponse|JsonResponse
    {
        $announcements->dismiss(Auth::user(), $announcement);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back();
    }
}
