<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformOwner\PlatformAnnouncementStatus;
use App\Library\PlatformOwner\Announcements\PlatformAnnouncementManager;
use App\Models\PlatformAnnouncement;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Platform Owner V1 final — Announcements: the management UI (draft,
 * schedule, publish, cancel, audience). Lifecycle lives in
 * PlatformAnnouncementManager; delivery is the PlatformAnnouncementDelivery
 * seam owned by Platform Automations. Supersedes the legacy immediate-blast
 * Announcements screen in the sidebar (its routes stay registered).
 */
class PlatformAnnouncementsController extends Controller
{
    public function __construct(private readonly PlatformAnnouncementManager $manager)
    {
    }

    public function index(Request $request): View
    {
        $this->authorize('view announcement');

        $filter = (string) $request->query('status', '');
        $rows = PlatformAnnouncement::query()->orderByDesc('id')->limit(200)->get();

        $rows = $rows->filter(fn (PlatformAnnouncement $a) => $filter === '' || $a->effectiveStatus()->value === $filter)->values();

        return view('admin.platform-announcements.index', [
            'rows' => $rows,
            'filter' => $filter,
            'statuses' => PlatformAnnouncementStatus::cases(),
            'breadcrumbs' => $this->crumbs(),
        ]);
    }

    public function create(): View
    {
        $this->authorize('create announcement');

        return $this->form(new PlatformAnnouncement(['audience' => 'all', 'channels' => ['in_app']]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create announcement');

        try {
            $a = $this->manager->createDraft((int) Auth::id(), $this->payload($request));
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return $this->afterSave($request, $a, __('Draft saved.'));
    }

    public function edit(string $uid): View
    {
        $this->authorize('edit announcement');

        return $this->form($this->find($uid));
    }

    public function update(Request $request, string $uid): RedirectResponse
    {
        $this->authorize('edit announcement');

        $a = $this->find($uid);

        try {
            $this->manager->update((int) Auth::id(), $a, $this->payload($request));
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return $this->afterSave($request, $a, __('Announcement saved.'));
    }

    public function transition(Request $request, string $uid, string $action): RedirectResponse
    {
        $this->authorize('edit announcement');

        $a = $this->find($uid);
        $actor = (int) Auth::id();

        try {
            match ($action) {
                'publish' => $this->manager->publishNow($actor, $a),
                'cancel' => $this->manager->cancel($actor, $a),
                'schedule' => $this->manager->schedule($actor, $a, $this->scheduledAt($request)),
                default => abort(404),
            };
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()->route('admin.platform-announcements.index')->with(['status' => 'success', 'message' => __('Announcement :a.', ['a' => ['publish' => 'published', 'cancel' => 'cancelled', 'schedule' => 'scheduled'][$action]])]);
    }

    /** Save and, if the schedule button was used, schedule in the same step. */
    private function afterSave(Request $request, PlatformAnnouncement $a, string $message): RedirectResponse
    {
        if ($request->filled('scheduled_at') && $request->input('submit') === 'schedule') {
            try {
                $this->manager->schedule((int) Auth::id(), $a, $this->scheduledAt($request));
            } catch (ValidationException $e) {
                return redirect()->route('admin.platform-announcements.edit', $a->uid)->withInput()->withErrors($e->errors());
            }

            $message = __('Announcement scheduled.');
        }

        return redirect()->route('admin.platform-announcements.index')->with(['status' => 'success', 'message' => $message]);
    }

    private function scheduledAt(Request $request): Carbon
    {
        $request->validate(['scheduled_at' => ['required', 'date']]);

        return Carbon::parse((string) $request->input('scheduled_at'), config('app.timezone'));
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $v = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', 'in:all,tiers'],
            'audience_tiers' => ['nullable', 'array'],
            'audience_tiers.*' => ['string'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['string'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $v['expires_at'] = filled($v['expires_at'] ?? null) ? Carbon::parse($v['expires_at'], config('app.timezone')) : null;

        return $v;
    }

    private function form(PlatformAnnouncement $a): View
    {
        return view('admin.platform-announcements.form', [
            'a' => $a,
            'tiers' => WorkspacePlanTier::cases(),
            'breadcrumbs' => $this->crumbs($a->exists ? 'Edit' : 'New announcement'),
        ]);
    }

    private function find(string $uid): PlatformAnnouncement
    {
        return PlatformAnnouncement::query()->where('uid', $uid)->firstOrFail();
    }

    /** @return array<int, array<string, mixed>> */
    private function crumbs(?string $trail = null): array
    {
        $home = ['link' => url(config('app.admin_path') . '/platform-owner'), 'name' => 'Home'];

        return $trail
            ? [$home, ['link' => route('admin.platform-announcements.index'), 'name' => 'Announcements'], ['name' => $trail]]
            : [$home, ['name' => 'Announcements']];
    }
}
