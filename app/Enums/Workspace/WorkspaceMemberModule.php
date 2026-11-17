<?php

namespace App\Enums\Workspace;

use App\Library\Ads\AdsFeatureAccess;

/**
 * The modules a Workspace owner can switch off for ONE team member.
 *
 * This is a NARROWING layer only. It never grants anything: a module a member's
 * role, Gate permissions or plan already refuse stays refused whatever is stored
 * here, and a stored "hidden" can only remove access that would otherwise exist.
 * It deliberately re-uses the two vocabularies the product already has instead of
 * inventing a third:
 *
 *  - featureKeys(): the PlatformFeature keys EntitlementManager decides. Hiding a
 *    module narrows the request's MenuEntitlements snapshot for that viewer only,
 *    so the sidebar, Home cards, dashboard links, assistant tools and every
 *    controller that reads the snapshot follow without a second code path.
 *  - routePrefixes(): the customer route-name prefixes the sidebar already uses to
 *    mark an entry active. The same list is the server-side block for direct URLs.
 *
 * Nothing here is consulted by background work (SEO audits, automations, syncs):
 * they decide through EntitlementManager with no viewer, so hiding a module from
 * a person never stops the module from running.
 */
enum WorkspaceMemberModule: string
{
    case Crm = 'crm';
    case Contacts = 'contacts';
    case Conversations = 'conversations';
    case Calendar = 'calendar';
    case Automations = 'automations';
    case Website = 'website';
    case Seo = 'seo';
    case Ads = 'ads';
    case Growth = 'growth';

    public function label(): string
    {
        return match ($this) {
            self::Crm => 'CRM & Opportunities',
            self::Contacts => 'Contacts',
            self::Conversations => 'Conversations',
            self::Calendar => 'Calendar',
            self::Automations => 'Automations',
            self::Website => 'Website',
            self::Seo => 'SEO',
            self::Ads => 'Ads',
            self::Growth => 'Next Best Move & Growth',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Crm => 'The sales board and opportunities.',
            self::Contacts => 'People and contact groups.',
            self::Conversations => 'The inbox and customer messages.',
            self::Calendar => 'Bookings and the schedule.',
            self::Automations => 'Workflows and their activity.',
            self::Website => 'The website, its pages and forms.',
            self::Seo => 'Keywords, Google Business Profile, citations, reviews, content and audits.',
            self::Ads => 'Google and Meta ads.',
            self::Growth => 'Home recommendations, the growth score and the advisor.',
        };
    }

    /**
     * @return list<string> PlatformFeature keys this module's surfaces are entitled by
     */
    public function featureKeys(): array
    {
        return match ($this) {
            self::Crm => ['crm'],
            self::Contacts => [],
            self::Conversations => ['conversations'],
            self::Calendar => ['calendar'],
            self::Automations => ['automations'],
            self::Website => ['website_generation'],
            self::Seo => ['seo_basic_visibility', 'seo_module', 'seo_rank_tracking', 'google_business_profile_module'],
            self::Ads => AdsFeatureAccess::gatedFeatureKeys(),
            self::Growth => ['ai_coo_basic'],
        };
    }

    /**
     * Route-name prefixes (or exact names) owned by this module. A module page,
     * its sub-pages and its JSON/AJAX endpoints all share them.
     *
     * @return list<string>
     */
    public function routePrefixes(): array
    {
        return match ($this) {
            self::Crm => ['customer.workspaces.businesses.crm.'],
            self::Contacts => [
                'customer.workspaces.businesses.people.',
                'customer.workspaces.businesses.contacts.',
                'customer.workspaces.businesses.contact.',
                'customer.contacts.',
                'customer.contact.',
            ],
            self::Conversations => ['customer.workspaces.businesses.conversations.', 'customer.chatbox.'],
            self::Calendar => ['customer.workspaces.businesses.calendar.'],
            self::Automations => ['customer.workspaces.businesses.automations.', 'customer.automations.'],
            self::Website => ['customer.workspaces.businesses.website.', 'customer.website.'],
            self::Seo => [
                'customer.workspaces.businesses.seo.',
                'customer.seo.',
                'customer.workspaces.businesses.gbp.',
                'customer.gbp.',
            ],
            self::Ads => ['customer.workspaces.businesses.ads.', 'customer.ads.'],
            self::Growth => [
                'customer.workspaces.businesses.growth.',
                'customer.opportunities.',
                'customer.workspaces.businesses.performance.explain',
            ],
        };
    }

    /**
     * Growth rule domains / categories whose recommendations belong to this module.
     * Used to keep a hidden module's recommendations out of Growth reads.
     *
     * @return list<string>
     */
    public function growthDomains(): array
    {
        return match ($this) {
            self::Crm => ['crm'],
            self::Conversations => ['conversations'],
            self::Calendar => ['booking'],
            self::Automations => ['automations'],
            self::Website => ['website'],
            self::Seo => ['seo', 'citations', 'reviews', 'rank'],
            self::Ads => ['ads'],
            default => [],
        };
    }

    /**
     * Growth rule categories owned by this module (a rule is this module's when
     * EITHER its domain or its category is listed — Google Business Profile
     * "local presence" and reviews recommendations belong to SEO).
     *
     * @return list<string>
     */
    public function growthCategories(): array
    {
        return match ($this) {
            self::Crm => ['sales_pipeline'],
            self::Conversations => ['lead_response'],
            self::Calendar => ['bookings'],
            self::Automations => ['automations'],
            self::Website => ['website'],
            self::Seo => ['seo', 'local_presence', 'reviews'],
            self::Ads => ['ads'],
            default => [],
        };
    }

    /**
     * The module that owns a route name, or null for a route no module owns
     * (Home, Settings, Billing, Team, ...), which this layer never touches.
     */
    public static function forRouteName(?string $routeName): ?self
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        foreach (self::cases() as $module) {
            foreach ($module->routePrefixes() as $prefix) {
                if (str_starts_with($routeName, $prefix)) {
                    return $module;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $m): string => $m->value, self::cases());
    }
}
