<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormSubmission;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Support\Facades\Auth;

/**
 * Forms V1 — the Forms authorization chain, in the ONE place that enforces its
 * order, shared by both Forms controllers. Same shape as
 * `AuthorizesCatalogRequests`:
 *
 *   1. TENANCY      — the canonical Workspace/Business chain. A foreign or
 *                     unknown Business is refused here, before capability or
 *                     entitlement is asked.                              -> 404
 *   2. CAPABILITY   — `forms` (config/customer-permissions.php). Reaching the
 *                     Business without it is refused regardless of tenancy.
 *                                                                       -> 401
 *   3. ENTITLEMENT  — `EntitlementManager` for `PlatformFeature::Forms`. NEVER
 *                     `website_generation`: Forms authorizes itself, so a
 *                     Business without the Website module still has Forms and
 *                     a Business with only Website does not.            -> 404
 *   4. LOCATION ACL — Location-scoped reads/actions only: the Location must
 *                     belong to THIS Business, then `LocationAccessGuard`
 *                     decides, fresh on every request.                  -> 404
 *
 * Definition management (the form itself) follows gates 1-3: a definition is
 * Business-wide. Submissions follow gate 4 for the Location they belong to.
 * NONE SUBSTITUTES FOR ANOTHER, and "not yours" and "not there" answer the
 * same 404 so an actor never learns a record they cannot reach exists.
 *
 * It authorizes; it applies no Forms rule — those stay in the domain classes.
 */
trait AuthorizesFormsRequests
{
    use ResolvesBusinessTenancy;

    /** The single capability key. One key, not a CRUD matrix. */
    protected const FORMS_CAPABILITY = 'forms';

    /**
     * Gates 1-3, in order.
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function formsScope(string $workspaceUid, string $businessUid): array
    {
        $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        // authorize() throws AuthorizationException, which this application
        // renders as 401 — the same refusal every other capability-gated
        // customer controller gives.
        $this->authorize(self::FORMS_CAPABILITY);

        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Forms->value);
    }

    /**
     * Gates 1-4, for an action on one Location (deploying a form there).
     *
     * @return array{0: Workspace, 1: Business, 2: BusinessLocation}
     */
    protected function formsLocationScope(string $workspaceUid, string $businessUid, string $locationUid): array
    {
        [$workspace, $business] = $this->formsScope($workspaceUid, $businessUid);

        $location = app(BusinessLocationRepository::class)->findForBusinessByUid($business, $locationUid);

        if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location)) {
            abort(404);
        }

        return [$workspace, $business, $location];
    }

    protected function formOrAbort(Business $business, string $formUid): Form
    {
        return Form::query()
            ->where('business_id', $business->id)
            ->where('uid', $formUid)
            ->first() ?? abort(404);
    }

    /**
     * A submission of THIS Business whose Location the actor may see, or 404.
     * A foreign Business's submission, an unknown uid and a sibling Location
     * the actor's grants do not cover are the same answer.
     */
    protected function visibleSubmissionOrAbort(Business $business, string $submissionUid): FormSubmission
    {
        $submission = FormSubmission::query()
            ->where('business_id', $business->id)
            ->where('uid', $submissionUid)
            ->first() ?? abort(404);

        $location = BusinessLocation::query()
            ->where('id', $submission->business_location_id)
            ->where('business_id', $business->id)
            ->first();

        if ($location === null || ! app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location)) {
            abort(404);
        }

        return $submission;
    }
}
