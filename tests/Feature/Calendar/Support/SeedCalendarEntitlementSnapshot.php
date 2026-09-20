<?php

namespace Tests\Feature\Calendar\Support;

use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\CustomerMenuBuilder;
use App\Library\Navigation\CustomerShellComposer;
use App\Library\Navigation\MenuEntitlements;
use Closure;
use Illuminate\Http\Request;
use ReflectionClass;

/**
 * TEST-ONLY. Never registered outside a test that pushes it explicitly.
 *
 * WHY THIS EXISTS. `PlatformFeature::Calendar` is `Planned` until Sub-slice
 * E, and `PlatformFeatureRegistry::AVAILABILITY` is a private const that only
 * a code deploy may change. So while Calendar is Planned, EntitlementManager
 * refuses every Calendar request before it reaches the controller, and no HTTP
 * test could ever exercise the behaviour BEHIND the gate — the day/week views,
 * the Location picker, the five lifecycle actions and the Location ACL. That
 * behaviour still has to be proven, so that Sub-slice E's flip is a one-line
 * change that is already known to be safe.
 *
 * WHAT IT SUBSTITUTES — exactly one thing. `EntitlementManager`,
 * `CustomerShellComposer` and `MenuEntitlements` are all `final`, so none can
 * be mocked or subclassed. But the composer memoizes its per-request snapshot
 * on a PUBLIC, DESIGNED request attribute
 * (CustomerShellComposer::MENU_ENTITLEMENTS_ATTRIBUTE), and
 * ResolvesBusinessTenancy::resolveEntitledBusinessTenancy() reads the answer
 * for a feature in CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES from that
 * snapshot. This middleware pre-fills it with the real snapshot's answers plus
 * `calendar => true` — the single decision that Sub-slice E will change.
 *
 * WHAT IT DOES NOT TOUCH. Gate 1 (Workspace/Business tenancy) and gate 3
 * (LocationAccessGuard) are the real production code, untouched, so the ACL
 * matrix these tests prove is genuine. The un-seeded path is proven separately,
 * with real Planned enforcement, by CalendarUiEntitlementGateTest.
 *
 * FAILS CLOSED, LOUDLY. The snapshot key below replicates
 * CustomerShellComposer::menuEntitlementsKey(), which is private. If that key
 * ever drifts, the composer simply misses this entry, rebuilds the real
 * snapshot, and every request 404s as Planned — so the tests fail visibly
 * rather than passing vacuously behind a bypass that stopped working.
 */
class SeedCalendarEntitlementSnapshot
{
    public function handle(Request $request, Closure $next)
    {
        $context = $request->attributes->get('customerContext');

        if ($context instanceof CustomerContext
            && $context->isBusinessFrame()
            && $context->frameWorkspace() !== null
            && $context->selectedBusiness !== null) {
            $key = implode('|', [
                'user:' . $context->userId,
                'frame:' . $context->frame->value,
                'workspace:' . $context->frameWorkspace()->id,
                'business:' . $context->selectedBusiness->id,
                'view-as:' . ($context->viewAs !== null ? $context->viewAs->sessionId : 'none'),
                'features:' . implode(',', CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES),
            ]);

            $allowed = array_fill_keys(CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES, true);

            // MenuEntitlements has a private constructor and readonly
            // properties by design. Both are initialised from INSIDE the
            // class's own scope (Closure::call binds it), which is the one way
            // a readonly property may be initialised. Built only here, in a test.
            $snapshot = (new ReflectionClass(MenuEntitlements::class))->newInstanceWithoutConstructor();
            (function () use ($allowed): void {
                $this->allowed = $allowed;
                $this->evaluated = true;
            })->call($snapshot);

            $built = $request->attributes->get(CustomerShellComposer::MENU_ENTITLEMENTS_ATTRIBUTE, []);
            $built = is_array($built) ? $built : [];
            $built[$key] = $snapshot;

            $request->attributes->set(CustomerShellComposer::MENU_ENTITLEMENTS_ATTRIBUTE, $built);
        }

        return $next($request);
    }
}
