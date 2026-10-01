<?php

namespace App\Library\Forms;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Forms\FormDeploymentSource;
use App\Library\Entitlement\CustomerAccountAccessGuard;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Forms\Exceptions\FormUnavailableException;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\FormVersion;
use App\Models\Workspace;
use Illuminate\Support\Str;

/**
 * Forms V1 — the ONE place a public submission's Location is decided, and the
 * authority stack a public form must pass before it renders or accepts
 * anything. Mirrors `PublicBookingController::resolve()` (Contract 15 §6).
 *
 * THE RULE: the Location is the one the DEPLOYMENT carries — the deployment
 * uid in the visitor's link is persisted, deterministic evidence of where the
 * lead belongs. Nothing else is ever consulted: not the visitor's IP or GPS,
 * not "the Business's first Location", not session state, not a Contact's
 * current Location. A visitor-posted `location_uid` may only RESTATE it (see
 * FormSubmissionService); it is never a source.
 *
 * FAIL CLOSED, ONE ANSWER. Every row is re-read from persistence and mutually
 * proven, then every refusal — unknown link, disabled deployment, form not
 * Active, archived or foreign Location, inactive/locked account, missing
 * `forms` entitlement — raises the same FormUnavailableException, which the
 * HTTP layer answers with a single 404. Note the entitlement is `forms`, never
 * `website_generation`: Forms authorizes itself.
 */
final class FormDeploymentResolver
{
    public function __construct(
        private readonly CustomerAccountAccessGuard $accounts,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * @throws FormUnavailableException
     */
    public function resolve(mixed $deploymentUid): FormDeploymentContext
    {
        if (! is_string($deploymentUid) || ! Str::isUuid($deploymentUid)) {
            throw new FormUnavailableException('unknown_link');
        }

        $deployment = FormDeployment::query()->where('uid', $deploymentUid)->first()
            ?? throw new FormUnavailableException('unknown_link');

        if (! $deployment->is_enabled || FormDeploymentSource::tryFrom($deployment->source) === null) {
            throw new FormUnavailableException('deployment_disabled');
        }

        $form = Form::query()->find($deployment->form_id) ?? throw new FormUnavailableException('form_missing');

        if (! $form->isActive()) {
            throw new FormUnavailableException('form_not_active');
        }

        $business = Business::query()->find($form->business_id) ?? throw new FormUnavailableException('business_missing');

        // The Location must be the deployment's own AND belong to the form's
        // Business — a Location of another Business is "not found", exactly as
        // a nonexistent one is.
        $location = BusinessLocation::query()
            ->where('id', $deployment->business_location_id)
            ->where('business_id', $business->id)
            ->first() ?? throw new FormUnavailableException('location_not_in_business');

        if (! $location->isActive()) {
            throw new FormUnavailableException('location_archived');
        }

        $workspace = Workspace::query()->find($business->workspace_id) ?? throw new FormUnavailableException('workspace_missing');

        if ($business->status !== BusinessStatus::Active || ! $workspace->is_active) {
            throw new FormUnavailableException('account_inactive');
        }

        if ($this->accounts->decisionForBusiness($business)->isLocked()) {
            throw new FormUnavailableException('account_locked');
        }

        if (! $this->entitlements->decide($workspace, $business, PlatformFeature::Forms->value, (int) $business->customer_id)->allowed) {
            throw new FormUnavailableException('not_entitled');
        }

        $version = FormVersion::query()
            ->where('form_id', $form->id)
            ->where('version', $form->current_version)
            ->first() ?? throw new FormUnavailableException('version_missing');

        return new FormDeploymentContext($deployment, $form, $version, $business, $location);
    }

    /**
     * Re-reads the version a visitor was SHOWN and proves it is a version of THIS
     * deployment's Form (a version of another Form or Business is not found), so
     * the flow can finish against exactly what it displayed even after the owner
     * published a newer one.
     *
     * This is identity only. $context came from resolve(), which has just
     * re-proven that the deployment, Form, Location, Business and account may
     * CURRENTLY accept submissions — pinning never bypasses that.
     *
     * @throws FormUnavailableException
     */
    public function pin(FormDeploymentContext $context, int $versionId): FormDeploymentContext
    {
        if ($versionId === (int) $context->version->id) {
            return $context;
        }

        $pinned = FormVersion::query()
            ->where('id', $versionId)
            ->where('form_id', $context->form->id)
            ->first() ?? throw new FormUnavailableException('version_not_of_this_form');

        return $context->withVersion($pinned);
    }
}
