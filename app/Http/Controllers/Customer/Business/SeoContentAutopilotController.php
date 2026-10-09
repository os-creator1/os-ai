<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoContent;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Jobs\Seo\RunContentAutopilotJob;
use App\Library\Seo\Content\Autopilot\AutopilotOverview;
use App\Library\Seo\Content\Autopilot\AutopilotPlanner;
use App\Library\Seo\Content\Autopilot\AutopilotSwitch;
use App\Library\Seo\Content\Autopilot\ContentProfile;
use App\Models\Business;
use App\Models\BusinessKnowledgeProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Content Autopilot (Contract 25) - the owner-facing controller. Thin: it resolves tenancy, authorizes and delegates.
 *
 * Every action rides the existing SeoModule entitlement (a Business without it gets a 404, exactly like Opportunities);
 * reads need `view_seo`, writes `manage_seo`. Nothing here calls an AI or a provider: turning Autopilot on or off, pausing it
 * and answering its one question are plain writes; the work itself happens on the queue.
 */
class SeoContentAutopilotController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesSeoBusinessTenancy;
    use ResolvesSeoContent;

    public function __construct(
        private readonly ContentProfile $profile,
        private readonly AutopilotOverview $overview,
        private readonly AutopilotSwitch $switch,
        private readonly AutopilotPlanner $planner,
    ) {
    }

    /** The owner's home for Content: on/off, what is next, this month, the one thing needed, what waits, what went live. */
    public function show(string $workspaceUid, string $businessUid): View
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        if ($this->websiteOf($business) === null) {
            return $this->noWebsite($workspaceUid, $businessUid, $business);
        }

        return view('customer.business.seo.content.autopilot.index', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'autopilot' => $this->overview->forBusiness($business),
        ]);
    }

    public function enable(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        if ($this->websiteOf($business) === null) {
            return $this->back($workspaceUid, $businessUid)->with('status', 'error')->with('message', 'Create your website first. Articles are published through it.');
        }

        $this->switch->enable($business, (int) $request->user()->id);

        // The first time, a few optional questions; skipping them is fine.
        if (! $this->profile->isCompleted($business)) {
            return redirect()->route('customer.workspaces.businesses.seo.content.autopilot.profile', [$workspaceUid, $businessUid])
                ->with('status', 'success')->with('message', 'Content Autopilot is on. A few quick questions will help it write like you - or skip them.');
        }

        return $this->back($workspaceUid, $businessUid)->with('status', 'success')->with('message', 'Content Autopilot is on.');
    }

    public function disable(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');
        $this->switch->disable($business);

        return $this->back($workspaceUid, $businessUid)->with('status', 'success')->with('message', 'Content Autopilot is off. Your articles are untouched.');
    }

    public function pause(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');
        $this->switch->pause($business);

        return $this->back($workspaceUid, $businessUid)->with('status', 'success')->with('message', 'Content Autopilot is paused.');
    }

    public function resume(string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');
        $this->switch->resume($business);

        return $this->back($workspaceUid, $businessUid)->with('status', 'success')->with('message', 'Content Autopilot is running again.');
    }

    /** Answer the ONE question Autopilot asked, then let it carry on. */
    public function answer(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $request->validate([
            'key' => ['required', 'string', 'in:common_questions,emphasis,differentiators'],
            'answer' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $this->profile->answer($business, (int) $request->user()->id, $input['key'], $input['answer']);
        } catch (ValidationException $e) {
            return $this->back($workspaceUid, $businessUid)->withErrors($e->errors());
        }

        $this->planner->closeAnsweredQuestions($business);

        if ($this->switch->isRunning($business)) {
            RunContentAutopilotJob::dispatch($business->id);
        }

        return $this->back($workspaceUid, $businessUid)->with('status', 'success')->with('message', 'Thank you - Autopilot will use that.');
    }

    /** The short first-enable Content Profile: only what MotionGrove cannot already know. */
    public function profile(string $workspaceUid, string $businessUid): View
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        $knowledge = BusinessKnowledgeProfile::query()->where('business_id', $business->id)->first();

        return view('customer.business.seo.content.autopilot.profile', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'profile' => $this->profile->get($business),
            'differentiators' => array_values(array_filter((array) ($knowledge?->differentiators ?? []), 'is_string')),
            'gaps' => $this->profile->gaps($business),
            'completed' => $this->profile->isCompleted($business),
        ]);
    }

    public function saveProfile(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->resolveContentModuleTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $request->validate([
            'common_questions' => ['nullable', 'string', 'max:2000'],
            'emphasis' => ['nullable', 'string', 'max:1000'],
            'avoid_topics' => ['nullable', 'string', 'max:1500'],
            'differentiators' => ['nullable', 'string', 'max:1000'],
        ]);

        // The differentiators box only exists while the Knowledge Profile has none; an empty answer changes nothing.
        $payload = array_filter($input, fn ($value) => $value !== null);

        try {
            $this->profile->save($business, (int) $request->user()->id, $payload);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()->route('customer.workspaces.businesses.seo.content.autopilot', [$workspaceUid, $businessUid])
            ->with('status', 'success')
            ->with('message', 'Saved. Content Autopilot will use this when it writes for you.');
    }

    private function back(string $workspaceUid, string $businessUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.seo.content.autopilot', [$workspaceUid, $businessUid]);
    }
}
