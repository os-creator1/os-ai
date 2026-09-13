<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Entitlement\BusinessFeatureSettings;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\CustomerMenuBuilder;
use App\Library\Navigation\CustomerShellComposer;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;

/**
 * Settings — a Business's Settings hub (owner decision).
 *
 * The sidebar's one Settings entry opens this page: cards for the
 * configuration a Business does not need day to day, each linking to its own,
 * existing screen (Business details, Locations, Text messaging, Billing, Plan
 * & subscription, Team). Nothing here is a second menu tree, and nothing here
 * decides access on its own — every module is built by
 * CustomerMenuBuilder::settingsSections(), with the same route, permission
 * and view-as gates as every menu entry, and every destination still enforces
 * its own authorization.
 *
 * A Core or Growth Business also carries its feature switches here, because
 * its account page is no longer shown: turning a module on or off stays one
 * click away, through the same enable/disable actions and the same
 * EntitlementManager authority. An Agency client Business does not — its
 * account-level controls live in the Agency account's own Settings.
 */
class BusinessSettingsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function show(
        string $workspaceUid,
        string $businessUid,
        CustomerShellComposer $shell,
        CustomerMenuBuilder $menu,
        EntitlementManager $entitlements,
    ): View {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        $user = Auth::user();
        $context = $shell->currentContext($user);

        // The hub describes the Business the shell is standing in; a request
        // whose context selected some other Business is not answered for this one.
        if ($context->selectedBusiness === null || $context->selectedBusiness->uid !== $business->uid) {
            abort(404);
        }

        $sections = $menu->settingsSections($context, $user, $shell->currentMenuEntitlements($context));

        $featureSettings = [];

        if (in_array($context->selectedWorkspace?->tier, [WorkspacePlanTier::Core, WorkspacePlanTier::Growth], true)
            && $context->canManageWorkspace()
            && ! $context->isViewingAsClient()) {
            $featureSettings = BusinessFeatureSettings::fromDecisions(
                $entitlements->decideAvailableFeaturesForBusiness($workspace, $business, (int) $user->id),
            );
        }

        return view('customer.settings.index', [
            'heading' => 'Settings',
            'subheading' => $business->name,
            'sections' => $sections,
            'featureSwitches' => $featureSettings === [] ? null : [
                'workspaceUid' => $workspace->uid,
                'businesses' => [['uid' => $business->uid, 'name' => $business->name]],
                'settings' => [$business->uid => $featureSettings],
                'showBusinessNames' => false,
            ],
        ]);
    }
}
