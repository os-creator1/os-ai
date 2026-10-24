<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanAssignmentStatus;

/**
 * Customer-facing grouping of PlatformFeature keys into the "modules" a
 * platform administrator switches per Workspace (Module Access).
 *
 * This is a PRESENTATION and MAPPING layer only. It owns no entitlement data
 * and makes no decision: every write goes through
 * EntitlementManager::applyWorkspaceModuleAccess() -> the existing
 * WorkspaceEntitlementOverride rows, and every enforcement point (menu,
 * route gates, mutation services) keeps reading EntitlementManager exactly as
 * before.
 *
 * Each module lists:
 *   keys        every feature key the module's customer surface consults. A
 *               Disabled module denies ALL of them (Ads has a documented edge:
 *               denying only ads_module leaves a still-granted legacy
 *               google_ads_module / meta_ads_module key open — see
 *               AdsFeatureAccess — so all four are denied).
 *   enable_keys the subset an explicit Enabled state allows. Legacy synonyms
 *               and paid third-party add-ons are deliberately left out: they
 *               are returned to the plan default rather than force-granted.
 *
 * Feature keys NOT mapped here (white_label, agency_package_capabilities,
 * messaging_transport) are not customer modules; they remain manageable only
 * through the existing advanced override form.
 */
final class WorkspaceModuleCatalog
{
    public const STATE_DEFAULT = 'default';

    public const STATE_ENABLED = 'enabled';

    public const STATE_DISABLED = 'disabled';

    /** Stored overrides that match no single state (set via the advanced form). */
    public const STATE_MIXED = 'mixed';

    /** @var array<string, array{label: string, description: string, keys: list<string>, enable_keys: list<string>}> */
    private const MODULES = [
        'crm' => [
            'label' => 'CRM & Contacts',
            'description' => 'Contacts, pipeline and opportunities.',
            'keys' => ['crm'],
            'enable_keys' => ['crm'],
        ],
        'conversations' => [
            'label' => 'Conversations',
            'description' => 'Inbox and customer messaging.',
            'keys' => ['conversations'],
            'enable_keys' => ['conversations'],
        ],
        'calendar' => [
            'label' => 'Calendar',
            'description' => 'Calendars and booking.',
            'keys' => ['calendar'],
            'enable_keys' => ['calendar'],
        ],
        'forms' => [
            'label' => 'Forms',
            'description' => 'Forms and lead capture.',
            'keys' => ['forms'],
            'enable_keys' => ['forms'],
        ],
        'automations' => [
            'label' => 'Automations',
            'description' => 'Workflows and automations.',
            'keys' => ['automations'],
            'enable_keys' => ['automations'],
        ],
        'websites' => [
            'label' => 'Websites',
            'description' => 'Website builder and published sites.',
            'keys' => ['website_generation'],
            'enable_keys' => ['website_generation'],
        ],
        'seo' => [
            'label' => 'SEO',
            'description' => 'SEO overview, keywords, audits, citations and reviews. Paid keyword rank tracking follows the plan unless disabled.',
            'keys' => ['seo_basic_visibility', 'seo_module', 'seo_rank_tracking'],
            'enable_keys' => ['seo_basic_visibility', 'seo_module'],
        ],
        'ads' => [
            'label' => 'Google / Meta Ads',
            'description' => 'Ads overview, Google Ads and Meta Ads. Disabling covers every Ads access key.',
            'keys' => ['ads_basic_visibility', 'ads_module', 'google_ads_module', 'meta_ads_module'],
            'enable_keys' => ['ads_basic_visibility', 'ads_module'],
        ],
        'google_business_profile' => [
            'label' => 'Google Business Profile',
            'description' => 'Business Profile listing management.',
            'keys' => ['google_business_profile_module'],
            'enable_keys' => ['google_business_profile_module'],
        ],
        'ai_assistant' => [
            'label' => 'AI Assistant',
            'description' => 'AI COO insights and assistant suggestions.',
            'keys' => ['ai_coo_basic'],
            'enable_keys' => ['ai_coo_basic'],
        ],
        'packages_payments' => [
            'label' => 'Packages / Payments',
            'description' => 'Packages & products catalog, proposals, contracts and invoices.',
            'keys' => ['packages_products', 'payments_contracts'],
            'enable_keys' => ['packages_products', 'payments_contracts'],
        ],
        'outreach' => [
            'label' => 'Outreach',
            'description' => 'Prospecting and outreach (Workspace level).',
            'keys' => ['prospect_outreach'],
            'enable_keys' => ['prospect_outreach'],
        ],
    ];

    /**
     * @return array<string, array{label: string, description: string, keys: list<string>, enable_keys: list<string>}>
     */
    public static function modules(): array
    {
        return self::MODULES;
    }

    public static function has(string $moduleId): bool
    {
        return isset(self::MODULES[$moduleId]);
    }

    /**
     * @return list<string> feature keys not owned by any module
     */
    public static function unmappedFeatureKeys(): array
    {
        $mapped = array_merge(...array_column(self::MODULES, 'keys'));

        return array_values(array_filter(
            array_map(static fn (PlatformFeature $f): string => $f->value, PlatformFeature::cases()),
            static fn (string $key): bool => ! in_array($key, $mapped, true),
        ));
    }

    /**
     * Whether an explicit Enabled state is legitimate: at least one key it
     * would allow is Available in the registry. EntitlementManager refuses to
     * Allow an unavailable feature, so offering the choice would only fail.
     */
    public static function canEnable(string $moduleId): bool
    {
        foreach (self::MODULES[$moduleId]['enable_keys'] as $key) {
            if (PlatformFeatureRegistry::isAvailable($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The override each key must end up with for a requested module state:
     * Deny, Allow, or null (= no override, plan default).
     *
     * @return array<string, WorkspaceEntitlementOverrideState|null>
     */
    public static function targetOverrides(string $moduleId, string $state): array
    {
        $module = self::MODULES[$moduleId];
        $targets = [];

        foreach ($module['keys'] as $key) {
            $targets[$key] = match ($state) {
                self::STATE_DISABLED => WorkspaceEntitlementOverrideState::Deny,
                self::STATE_ENABLED => in_array($key, $module['enable_keys'], true) && PlatformFeatureRegistry::isAvailable($key)
                    ? WorkspaceEntitlementOverrideState::Allow
                    : null,
                default => null,
            };
        }

        return $targets;
    }

    /**
     * The module's stored state, derived from the Workspace's override rows.
     *
     * @param  array<string, WorkspaceEntitlementOverrideState>  $overrides  feature key => state
     */
    public static function storedState(string $moduleId, array $overrides): string
    {
        $current = [];

        foreach (self::MODULES[$moduleId]['keys'] as $key) {
            $current[$key] = $overrides[$key] ?? null;
        }

        if (array_filter($current) === []) {
            return self::STATE_DEFAULT;
        }

        foreach ([self::STATE_DISABLED, self::STATE_ENABLED] as $state) {
            if (self::targetOverrides($moduleId, $state) === $current) {
                return $state;
            }
        }

        return self::STATE_MIXED;
    }

    /**
     * The module's EFFECTIVE Workspace-level access, from the entitlement
     * summary EntitlementManager already produced (same precedence as
     * decide(): override, else plan mapping, then plan status). A module is
     * on when any of its keys is entitled, matching how AdsFeatureAccess and
     * the SEO gates treat their keys. Per-Business toggles are not included.
     *
     * @return array{on: bool, reason: string}
     */
    public static function effective(string $moduleId, WorkspaceEntitlementSummary $summary): array
    {
        if (! $summary->isAssigned) {
            return ['on' => false, 'reason' => 'No plan assigned'];
        }

        $viaPlan = false;
        $viaOverride = false;
        $denied = false;

        foreach (self::MODULES[$moduleId]['keys'] as $key) {
            if (! PlatformFeatureRegistry::isAvailable($key)) {
                continue;
            }

            $override = $summary->overrides[$key] ?? null;

            if ($override === WorkspaceEntitlementOverrideState::Deny) {
                $denied = true;
            } elseif ($override === WorkspaceEntitlementOverrideState::Allow) {
                $viaOverride = true;
            } elseif (in_array($key, $summary->planFeatureKeys, true)) {
                $viaPlan = true;
            }
        }

        if (! $viaPlan && ! $viaOverride) {
            return ['on' => false, 'reason' => $denied ? 'Disabled by platform admin' : 'Not included in plan'];
        }

        if ($summary->status === WorkspacePlanAssignmentStatus::Suspended) {
            return ['on' => false, 'reason' => 'Plan suspended'];
        }

        if ($summary->status === WorkspacePlanAssignmentStatus::Inactive) {
            return ['on' => false, 'reason' => 'Plan inactive'];
        }

        return ['on' => true, 'reason' => $viaOverride && ! $viaPlan ? 'Enabled by platform admin' : 'Included in plan'];
    }
}
