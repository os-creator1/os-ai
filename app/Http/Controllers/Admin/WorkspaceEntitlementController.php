<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\Entitlement\BusinessSlotAllocationRequiredException;
use App\Exceptions\Entitlement\BusinessSlotLimitExceededException;
use App\Exceptions\Entitlement\InactiveWorkspacePlanException;
use App\Exceptions\Entitlement\InvalidAdditionalBusinessSlotsException;
use App\Exceptions\Entitlement\PlanCatalogPricingInUseException;
use App\Exceptions\Entitlement\SuspendedWorkspacePlanException;
use App\Exceptions\Entitlement\UnavailablePlatformFeatureOverrideException;
use App\Exceptions\Entitlement\UndefinedPlanPricingException;
use App\Exceptions\Entitlement\WorkspacePlanAlreadyAssignedException;
use App\Exceptions\Entitlement\WorkspacePlanUnassignedException;
use App\Exceptions\Workspace\WorkspaceNotFoundException;
use App\Http\Requests\Admin\Workspace\AssignWorkspacePlanRequest;
use App\Http\Requests\Admin\Workspace\ChangeWorkspacePlanRequest;
use App\Http\Requests\Admin\Workspace\ChangeWorkspacePlanStatusRequest;
use App\Http\Requests\Admin\Workspace\GrantWorkspaceComplimentaryStatusRequest;
use App\Http\Requests\Admin\Workspace\RevokeWorkspaceComplimentaryStatusRequest;
use App\Http\Requests\Admin\Workspace\StoreWorkspaceEntitlementOverrideRequest;
use App\Http\Requests\Admin\Workspace\UpdateWorkspaceAdditionalSlotsRequest;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Throwable;

/**
 * Admin Workspace plan/capacity/entitlement mutation surface (RFC-004
 * Milestone 3). Every action delegates entirely to EntitlementManager --
 * this controller never reads or writes an entitlement repository directly
 * (§8/§20). EntitlementManager independently re-checks platform-
 * administrator authority regardless of this controller's own Gate check.
 */
class WorkspaceEntitlementController extends AdminBaseController
{
    public function __construct(
        private readonly EntitlementManager $entitlementManager,
    ) {
    }

    public function assignPlan(AssignWorkspacePlanRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $additionalBusinessSlots = (int) $request->validated('additional_business_slots', 0);

        try {
            $this->entitlementManager->assignFirstPlan(
                $workspace,
                WorkspacePlanTier::from($request->validated('tier')),
                (int) Auth::id(),
                $request->validated('reason'),
                $request->boolean('is_complimentary'),
                $additionalBusinessSlots,
            );
        } catch (WorkspaceNotFoundException) {
            abort(404);
        } catch (Throwable $e) {
            return $this->mutationErrorRedirect($workspace, $e);
        }

        return $this->mutationSuccessRedirect($workspace, 'Workspace plan assigned.');
    }

    public function changePlan(ChangeWorkspacePlanRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $additionalBusinessSlots = $request->validated('additional_business_slots');

        if ($additionalBusinessSlots !== null) {
            $additionalBusinessSlots = (int) $additionalBusinessSlots;
        }

        try {
            $this->entitlementManager->changePlan(
                $workspace,
                WorkspacePlanTier::from($request->validated('tier')),
                (int) Auth::id(),
                $request->validated('reason'),
                $additionalBusinessSlots,
            );
        } catch (WorkspaceNotFoundException) {
            abort(404);
        } catch (Throwable $e) {
            return $this->mutationErrorRedirect($workspace, $e);
        }

        return $this->mutationSuccessRedirect($workspace, 'Workspace plan changed.');
    }

    public function changeStatus(ChangeWorkspacePlanStatusRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        try {
            $this->entitlementManager->changePlanStatus(
                $workspace,
                WorkspacePlanAssignmentStatus::from($request->validated('status')),
                (int) Auth::id(),
                $request->validated('reason'),
            );
        } catch (WorkspaceNotFoundException) {
            abort(404);
        } catch (Throwable $e) {
            return $this->mutationErrorRedirect($workspace, $e);
        }

        return $this->mutationSuccessRedirect($workspace, 'Workspace plan status changed.');
    }

    public function grantComplimentary(GrantWorkspaceComplimentaryStatusRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        try {
            $this->entitlementManager->grantComplimentaryStatus(
                $workspace,
                (int) Auth::id(),
                $request->validated('reason'),
            );
        } catch (WorkspaceNotFoundException) {
            abort(404);
        } catch (Throwable $e) {
            return $this->mutationErrorRedirect($workspace, $e);
        }

        return $this->mutationSuccessRedirect($workspace, 'Complimentary status granted.');
    }

    public function revokeComplimentary(RevokeWorkspaceComplimentaryStatusRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        try {
            $this->entitlementManager->revokeComplimentaryStatus(
                $workspace,
                (int) Auth::id(),
                $request->validated('reason'),
            );
        } catch (WorkspaceNotFoundException) {
            abort(404);
        } catch (Throwable $e) {
            return $this->mutationErrorRedirect($workspace, $e);
        }

        return $this->mutationSuccessRedirect($workspace, 'Complimentary status revoked.');
    }

    public function updateAdditionalSlots(UpdateWorkspaceAdditionalSlotsRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $additionalBusinessSlots = (int) $request->validated('additional_business_slots');

        try {
            $this->entitlementManager->setAdditionalBusinessSlots(
                $workspace,
                $additionalBusinessSlots,
                (int) Auth::id(),
                $request->validated('reason'),
            );
        } catch (WorkspaceNotFoundException) {
            abort(404);
        } catch (Throwable $e) {
            return $this->mutationErrorRedirect($workspace, $e);
        }

        return $this->mutationSuccessRedirect($workspace, 'Additional Business slots updated.');
    }

    public function storeOverride(StoreWorkspaceEntitlementOverrideRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        try {
            $this->entitlementManager->createOrChangeOverride(
                $workspace,
                PlatformFeature::from($request->validated('feature_key')),
                WorkspaceEntitlementOverrideState::from($request->validated('state')),
                (int) Auth::id(),
                $request->validated('reason'),
            );
        } catch (WorkspaceNotFoundException) {
            abort(404);
        } catch (Throwable $e) {
            return $this->mutationErrorRedirect($workspace, $e);
        }

        return $this->mutationSuccessRedirect($workspace, 'Workspace entitlement override saved.');
    }

    public function revertOverride(Workspace $workspace, string $featureKey): RedirectResponse
    {
        $this->authorize('manage workspace plans');

        $feature = PlatformFeature::tryFrom($featureKey);

        if ($feature === null) {
            abort(404);
        }

        try {
            $this->entitlementManager->revertOverride($workspace, $feature, (int) Auth::id());
        } catch (WorkspaceNotFoundException) {
            abort(404);
        }

        return $this->mutationSuccessRedirect($workspace, 'Workspace entitlement override reverted.');
    }

    private function mutationSuccessRedirect(Workspace $workspace, string $message): RedirectResponse
    {
        return redirect()->route('admin.workspaces.show', $workspace)->with('flash_success', $message);
    }

    /**
     * Every typed App\Exceptions\Entitlement\* exception and
     * InvalidArgumentException (reason/pairing validation) mapped to a
     * specific, human-readable message -- never the raw exception message
     * text.
     */
    private function mutationErrorRedirect(Workspace $workspace, Throwable $e): RedirectResponse
    {
        $message = match (true) {
            $e instanceof WorkspacePlanAlreadyAssignedException => 'This Workspace already has a plan assigned.',
            $e instanceof WorkspacePlanUnassignedException => 'This Workspace has no plan assigned.',
            $e instanceof InactiveWorkspacePlanException => 'This Workspace plan is currently inactive.',
            $e instanceof SuspendedWorkspacePlanException => 'This Workspace plan is currently suspended.',
            $e instanceof BusinessSlotAllocationRequiredException => 'An additional Business slot allocation is required.',
            $e instanceof BusinessSlotLimitExceededException => 'This Workspace has reached its Business slot limit.',
            $e instanceof UndefinedPlanPricingException => 'This plan catalog tier has no defined pricing; contact engineering before proceeding.',
            $e instanceof InvalidAdditionalBusinessSlotsException => 'The requested additional Business slot count is not valid for this plan tier.',
            $e instanceof UnavailablePlatformFeatureOverrideException => 'This feature cannot be allowed by override because it is not yet available.',
            $e instanceof PlanCatalogPricingInUseException => 'This plan catalog pricing cannot be changed while Workspaces are actively using it.',
            $e instanceof InvalidArgumentException => 'This plan change is not valid for the current state.',
            default => throw $e,
        };

        return redirect()->route('admin.workspaces.show', $workspace)->with('flash_error', $message);
    }
}
