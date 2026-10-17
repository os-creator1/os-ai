<?php

namespace App\Library\Dashboard\Home;

use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\MenuEntitlements;
use App\Library\Navigation\WorkspaceCandidate;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Home V2 — which in-Home actions this actor may open, and where each one's
 * "Open full module" goes.
 *
 * ONE decision, shared by the page (to render buttons) and by the Ask
 * MotionGrove resolver (to answer a typed request), so a drawer is never
 * offered by one and refused by the other. Every answer passes the SAME
 * DashboardLinkGate the rest of Home uses — the four-way rule: route exists,
 * actor holds a permission that reaches it, the context (view-as) allows it,
 * and the feature is entitled. A drawer is therefore never wider than the
 * module page behind it; and the write each drawer performs is the module's
 * own endpoint, which re-checks everything independently.
 *
 * The drawer FRAGMENT route is business-scoped, so view-as classifies it
 * BusinessScoped and only the viewed pair can open it.
 */
final class HomeActionRouter
{
    /** The closed set of drawer keys the fragment route serves. */
    public const DRAWERS = ['follow-ups', 'booking-type', 'appointment', 'upcoming', 'ads'];

    public function __construct(private readonly DashboardLinkGate $links)
    {
    }

    /** The frame Workspace for these scoped uids, from the context already resolved for this request (no extra read). */
    private function workspaceFor(CustomerContext $context, array $scoped): ?WorkspaceCandidate
    {
        foreach ($context->workspaces as $workspace) {
            if ($workspace->uid === ($scoped[0] ?? null)) {
                return $workspace;
            }
        }

        return null;
    }

    /**
     * BusinessEmailController's rule for connecting a mailbox: the active Workspace owner or an actor holding manage_business_email, never
     * while viewing as a client. Merely being able to VIEW the email page (chat_box) does not allow connecting.
     */
    private function mayConnectEmail(CustomerContext $context, User $user, array $scoped): bool
    {
        if ($context->isViewingAsClient()) {
            return false;
        }

        $workspace = $this->workspaceFor($context, $scoped);

        if ($workspace !== null && $workspace->isActive && $workspace->ownerUserId === (int) $user->id) {
            return true;
        }

        return Gate::forUser($user)->allows('manage_business_email');
    }

    /**
     * StripeConnectManager's owner-only rule (§6.2): the Business's own customer or the owner of the Workspace holding it. An ordinary
     * member with access_backend cannot connect payments.
     */
    private function ownsBusiness(CustomerContext $context, User $user, array $scoped): bool
    {
        $business = $context->selectedBusiness;

        if ($business !== null && $business->uid === ($scoped[1] ?? null) && $business->customerId === (int) $user->id) {
            return true;
        }

        $workspace = $this->workspaceFor($context, $scoped);

        return $workspace !== null && $workspace->ownerUserId === (int) $user->id;
    }

    /**
     * @param  array<int, string>  $scoped  [workspaceUid, businessUid]
     * @param  ?string  $locationUid  the first Location this actor may reach (Calendar actions live under one)
     * @return array<string, array{drawer: ?string, drawerUrl: ?string, fullUrl: ?string, label: string}|null>
     */
    public function forActor(CustomerContext $context, User $user, MenuEntitlements $entitlements, array $scoped, ?string $locationUid): array
    {
        $fragment = fn (string $drawer, array $permissions, ?string $feature): ?string => $this->links->url(
            $context, $user, $entitlements,
            'customer.workspaces.businesses.home.drawer',
            array_merge($scoped, [$drawer]),
            $permissions,
            $feature,
        );

        $loc = $locationUid === null ? null : array_merge($scoped, [$locationUid]);

        $followFull = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.conversations.index', $scoped, ['chat_box'], 'conversations');
        $followDrawer = $followFull === null ? null : $fragment('follow-ups', ['chat_box'], 'conversations');

        $typeFull = $loc === null ? null : $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.booking-types.create', $loc, ['access_backend'], 'calendar');
        $typeDrawer = $typeFull === null ? null : $fragment('booking-type', ['access_backend'], 'calendar');

        $apptFull = $loc === null ? null : $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.appointments.create', $loc, ['access_backend'], 'calendar');
        $apptDrawer = $apptFull === null ? null : $fragment('appointment', ['access_backend'], 'calendar');

        $upcomingFull = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.index', $scoped, ['access_backend'], 'calendar');
        $upcomingDrawer = $upcomingFull === null ? null : $fragment('upcoming', ['access_backend'], 'calendar');

        $adsFull = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.ads.overview', $scoped, ['view_google_ads', 'view_meta_ads'], 'ads_basic_visibility');
        $growthAll = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.growth.opportunities.index', $scoped, ['business_advisor'], 'ai_coo_basic');
        // The ads drawer reads the Growth engine's ads recommendations, so it needs Growth access;
        // the module page it hands off to is the provider's own overview.
        $adsDrawer = $growthAll === null ? null : $fragment('ads', ['business_advisor'], 'ai_coo_basic');

        return [
            'follow-ups' => $followFull === null && $followDrawer === null ? null : [
                'drawer' => $followDrawer === null ? null : 'follow-ups', 'drawerUrl' => $followDrawer, 'fullUrl' => $followFull, 'label' => 'Review follow-ups',
            ],
            'booking-type' => $typeFull === null ? null : [
                'drawer' => $typeDrawer === null ? null : 'booking-type', 'drawerUrl' => $typeDrawer, 'fullUrl' => $typeFull, 'label' => 'Create a booking type',
            ],
            'appointment' => $apptFull === null ? null : [
                'drawer' => $apptDrawer === null ? null : 'appointment', 'drawerUrl' => $apptDrawer, 'fullUrl' => $apptFull, 'label' => 'Book an appointment',
            ],
            'upcoming' => $upcomingFull === null ? null : [
                'drawer' => $upcomingDrawer === null ? null : 'upcoming', 'drawerUrl' => $upcomingDrawer, 'fullUrl' => $upcomingFull, 'label' => 'Upcoming appointments',
            ],
            'ads' => $adsFull === null && $adsDrawer === null ? null : [
                'drawer' => $adsDrawer === null ? null : 'ads', 'drawerUrl' => $adsDrawer, 'fullUrl' => $adsFull ?? $growthAll, 'label' => 'Review ads recommendation',
            ],
            'website' => ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.website.show', $scoped, ['website'], 'website_generation')) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Update your website',
            ],
            'contact' => ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.people.add', $scoped, DashboardLinkGate::CONTACT_PERMISSIONS)) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Add a contact',
            ],
            'form' => ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.forms.create', $scoped, ['forms'], 'forms')) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Add a form to your website',
            ],
            'automation' => ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.automations.workflows.create', $scoped, ['automations'], 'automations')) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Set one up',
            ],
            'bookings-list' => ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.calendar.bookings', $scoped, ['access_backend'], 'calendar')) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Bookings',
            ],
            'contacts-list' => ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.people.index', $scoped, ['view_contact'])) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Contacts',
            ],
            'email' => ! $this->mayConnectEmail($context, $user, $scoped) || ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.email.show', $scoped, [])) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Connect your email',
            ],
            'catalog' => ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.catalog.create', $scoped, ['packages_products'], 'packages_products')) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Add packages and prices',
            ],
            'payments' => ! $this->ownsBusiness($context, $user, $scoped) || ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.payments.connect.show', $scoped, ['payments_contracts'], 'payments_contracts')) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Connect payments',
            ],
            'advisor' => ($u =$this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.growth.advisor', $scoped, ['business_advisor'], 'ai_coo_basic')) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Ask the Advisor',
            ],
        ];
    }
}
