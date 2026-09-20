<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Seo\SeoReviewRequestChannel;
use App\Enums\Seo\SeoReviewRequestStatus;
use App\Exceptions\Seo\SeoReviewException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Seo\SeoReviewLinkManager;
use App\Library\Seo\SeoReviewRequestManager;
use App\Library\Seo\SeoReviewsPageReader;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Contract 18 Sub-slice 18F — Reviews: WORKFLOW TRACKING ONLY.
 *
 * A Business keeps a manual review link per Location and a ledger of "we asked
 * this person", with a cooldown. It stores no review, rating or reviewer, and
 * SEO SENDS NOTHING: this controller has no path to Messaging, Conversations
 * or Automations other than read-only links rendered by the view.
 *
 * Thin by design: every rule (Location ACL, Contact/Location equality, the
 * `view_contact` PII gate, the cooldown, https-only links) lives in the
 * managers. The chain here (Contract 18 §10.1): Workspace by uid -> Business
 * inside it -> userCanAccessBusiness() -> Business Active -> the entitlement
 * decision for PlatformFeature::SeoModule -> the capability gate
 * (`view_seo` to read, `manage_seo` to write). Every tenancy, entitlement,
 * Location or record mismatch is `abort(404)`; no implicit route-model
 * binding.
 *
 * FAIL-CLOSED WHILE `Planned`. SeoModule is Planned until Sub-slice H, so
 * every route here answers 404 today.
 *
 * INPUTS ARE CLOSED. The only request fields read are review_url, channel,
 * contact_uid and crm_opportunity_uid. There is no rating, sentiment,
 * satisfaction, incentive, quota or target input anywhere, and anything else
 * a caller sends is ignored.
 */
class SeoReviewsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(
        private readonly SeoReviewsPageReader $reader,
        private readonly SeoReviewLinkManager $links,
        private readonly SeoReviewRequestManager $requests,
    ) {
    }

    public function reviews(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->resolveReviewTenancy($workspaceUid, $businessUid);

        $this->authorize('view_seo');

        return view('customer.business.seo.reviews', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'business' => $business,
            'sections' => $this->reader->read($workspace, $business, Auth::user()),
            'channels' => SeoReviewRequestChannel::cases(),
            'canManage' => Auth::user()->can('manage_seo'),
        ]);
    }

    public function saveLink(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveReviewTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $request->validate(['review_url' => ['required', 'string', 'max:2048']]);

        return $this->attempt($workspaceUid, $businessUid, 'Review link saved.', function () use ($business, $locationUid, $input): void {
            $this->links->set((int) Auth::id(), $business, $locationUid, $input['review_url']);
        });
    }

    public function clearLink(string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveReviewTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        return $this->attempt($workspaceUid, $businessUid, 'Review link removed.', function () use ($business, $locationUid): void {
            $this->links->clear((int) Auth::id(), $business, $locationUid);
        });
    }

    public function recordRequest(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        [, $business] = $this->resolveReviewTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        $input = $request->validate([
            'channel' => ['required', Rule::in(array_map(fn (SeoReviewRequestChannel $channel) => $channel->value, SeoReviewRequestChannel::cases()))],
            'contact_uid' => ['nullable', 'string', 'max:64'],
            'crm_opportunity_uid' => ['nullable', 'string', 'max:64'],
        ]);

        return $this->attempt($workspaceUid, $businessUid, 'Review request recorded. Nothing was sent — use Conversations or an Automation to contact the person.', function () use ($business, $locationUid, $input): void {
            $this->requests->record(
                Auth::user(),
                $business,
                $locationUid,
                $input['channel'],
                ($input['contact_uid'] ?? null) !== null && $input['contact_uid'] !== '' ? $input['contact_uid'] : null,
                ($input['crm_opportunity_uid'] ?? null) !== null && $input['crm_opportunity_uid'] !== '' ? $input['crm_opportunity_uid'] : null,
            );
        });
    }

    public function markReviewed(string $workspaceUid, string $businessUid, string $requestUid): RedirectResponse
    {
        return $this->resolve($workspaceUid, $businessUid, $requestUid, SeoReviewRequestStatus::Reviewed);
    }

    public function markDeclined(string $workspaceUid, string $businessUid, string $requestUid): RedirectResponse
    {
        return $this->resolve($workspaceUid, $businessUid, $requestUid, SeoReviewRequestStatus::Declined);
    }

    private function resolve(string $workspaceUid, string $businessUid, string $requestUid, SeoReviewRequestStatus $outcome): RedirectResponse
    {
        [, $business] = $this->resolveReviewTenancy($workspaceUid, $businessUid);

        $this->authorize('manage_seo');

        return $this->attempt($workspaceUid, $businessUid, 'Outcome recorded.', function () use ($business, $requestUid, $outcome): void {
            $this->requests->resolve((int) Auth::id(), $business, $requestUid, $outcome->value);
        });
    }

    /**
     * Runs a manager call. Access denied is a 404 (a guessed or inaccessible
     * record is indistinguishable from a missing one); every other refusal is
     * calm fixed copy back on the page, never echoing input.
     */
    private function attempt(string $workspaceUid, string $businessUid, string $success, callable $action): RedirectResponse
    {
        $back = redirect()->route('customer.workspaces.businesses.seo.reviews.index', [$workspaceUid, $businessUid]);

        try {
            $action();
        } catch (SeoReviewException $e) {
            if ($e->reason === SeoReviewException::ACCESS_DENIED) {
                abort(404);
            }

            return $back->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }

        return $back->with(['status' => 'success', 'message' => $success]);
    }

    /**
     * The chain through entitlement. Its own method so a test-only subclass
     * can replace exactly and only this step.
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function resolveReviewTenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::SeoModule->value);
    }
}
