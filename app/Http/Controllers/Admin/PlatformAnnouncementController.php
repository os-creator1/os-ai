<?php

namespace App\Http\Controllers\Admin;

use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementAudience;
use App\Library\PlatformAutomation\Announcements\PlatformAnnouncementManager;
use App\Models\PlatformAnnouncement;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Platform Owner UI for the Announcements system (banner / in-app notification / email,
 * audience-targeted, scheduled, with an expiry). Thin: PlatformAnnouncementManager owns
 * every transition and the queued, chunked delivery.
 */
class PlatformAnnouncementController extends AdminBaseController
{
    public function __construct(private readonly PlatformAnnouncementManager $announcements)
    {
    }

    public function list(): View
    {
        $this->owner();

        return view('admin.platform-announcements.index', [
            'announcements' => PlatformAnnouncement::query()->orderByDesc('id')->paginate(20),
            'breadcrumbs' => $this->crumbs(),
        ]);
    }

    public function create(): View
    {
        $this->owner();

        return $this->form(new PlatformAnnouncement([
            'severity' => 'info', 'channels' => ['banner', 'notification'], 'audience' => ['kind' => 'everyone'],
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->owner();
        $this->requireScheduleTime($request);
        $announcement = $this->announcements->create($this->input($request), (int) Auth::id());

        return $this->afterSave($request, $announcement, 'Saved as a draft.');
    }

    public function edit(PlatformAnnouncement $announcement): View
    {
        $this->owner();

        return $this->form($announcement);
    }

    public function update(Request $request, PlatformAnnouncement $announcement): RedirectResponse
    {
        $this->owner();
        $this->requireScheduleTime($request);
        $this->announcements->update($announcement, $this->input($request));

        return $this->afterSave($request, $announcement->refresh(), 'Saved.');
    }

    public function cancel(PlatformAnnouncement $announcement): RedirectResponse
    {
        $this->owner();
        $this->announcements->cancel($announcement);

        return redirect()->route('admin.platform-announcements.index')->with(['status' => 'success', 'message' => 'Cancelled. The banner stops showing; notices already delivered stay.']);
    }

    /** "Save" buttons: draft (default), schedule, or publish now — all through the manager. */
    private function afterSave(Request $request, PlatformAnnouncement $announcement, string $message): RedirectResponse
    {
        $mode = (string) $request->input('mode', 'draft');

        if ($mode === 'schedule') {
            $this->announcements->schedule($announcement);
            $message = 'Scheduled for ' . $announcement->publish_at->format('M j, Y g:i A') . '.';
        } elseif ($mode === 'publish') {
            $this->announcements->publishNow($announcement);
            $message = 'Published. Delivery is running in the background.';
        }

        return redirect()->route('admin.platform-announcements.index')->with(['status' => 'success', 'message' => $message]);
    }

    /** Checked BEFORE anything is saved, so a refused schedule never leaves a stray draft behind. */
    private function requireScheduleTime(Request $request): void
    {
        if ($request->input('mode') === 'schedule' && trim((string) $request->input('publish_at')) === '') {
            throw ValidationException::withMessages(['publish_at' => ['Choose when to publish it.']]);
        }
    }

    private function form(PlatformAnnouncement $announcement): View
    {
        return view('admin.platform-announcements.form', [
            'announcement' => $announcement,
            'kinds' => PlatformAnnouncementAudience::KINDS,
            'tiers' => PlatformAnnouncementAudience::TIERS,
            'breadcrumbs' => $this->crumbs($announcement->exists ? 'Edit' : 'New'),
        ]);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        $audience = (array) $request->input('audience', []);

        return [
            'title' => $request->input('title'),
            'body' => $request->input('body'),
            'severity' => $request->input('severity'),
            'channels' => (array) $request->input('channels', []),
            'audience' => $audience,
            'publish_at' => $request->input('mode') === 'publish' ? null : $request->input('publish_at'),
            'expires_at' => $request->input('expires_at'),
        ];
    }

    private function owner(): void
    {
        abort_unless(Auth::user()?->is_admin, 404);
    }

    /** @return array<int, array<string, string>> */
    private function crumbs(?string $trailing = null): array
    {
        $crumbs = [['link' => route('admin.platform-owner.overview'), 'name' => 'Platform Owner'], ['name' => 'Announcements']];

        if ($trailing !== null) {
            $crumbs[1] = ['link' => route('admin.platform-announcements.index'), 'name' => 'Announcements'];
            $crumbs[] = ['name' => $trailing];
        }

        return $crumbs;
    }
}
