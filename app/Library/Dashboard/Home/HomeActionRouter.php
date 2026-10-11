<?php

namespace App\Library\Dashboard\Home;

use App\Library\Dashboard\DashboardLinkGate;
use App\Library\Navigation\CustomerContext;
use App\Library\Navigation\MenuEntitlements;
use App\Models\User;

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
            'advisor' => ($u = $this->links->url($context, $user, $entitlements, 'customer.workspaces.businesses.growth.advisor', $scoped, ['business_advisor'], 'ai_coo_basic')) === null ? null : [
                'drawer' => null, 'drawerUrl' => null, 'fullUrl' => $u, 'label' => 'Ask the Advisor',
            ],
        ];
    }
}
