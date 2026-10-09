<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoBusinessTenancy;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesSeoContent;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Seo\Content\Autopilot\ContentProfile;
use App\Models\BusinessKnowledgeProfile;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Content Autopilot (Contract 25) — the owner-facing controller. Thin: it resolves tenancy, authorizes and delegates.
 *
 * Every action rides the existing SeoModule entitlement (a Business without it gets a 404, exactly like Opportunities);
 * reads need `view_seo`, writes `manage_seo`. Nothing here calls an AI or a provider.
 */
class SeoContentAutopilotController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;
    use ResolvesSeoBusinessTenancy;
    use ResolvesSeoContent;

    public function __construct(private readonly ContentProfile $profile)
    {
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

        return redirect()->route('customer.workspaces.businesses.seo.content.autopilot.profile', [$workspaceUid, $businessUid])
            ->with('status', 'success')
            ->with('message', 'Saved. Content Autopilot will use this when it writes for you.');
    }
}
