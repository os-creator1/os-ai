<?php

namespace App\Library\Navigation;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Navigation\NavigationDisposition;

/**
 * Customer navigation coherence pass, Phase 13 — the closed, code-backed
 * record of how every Available PlatformFeature is reached from the
 * customer shell.
 *
 * WHY THIS EXISTS. CustomerMenuBuilder wires up top-level entries; the
 * Settings hub wires up Settings cards; a module's own Blade views wire up
 * their own child tabs. Nothing previously forced those three (plus "Business
 * Home only", "Account frame only" and "deliberately none") to stay in sync
 * with PlatformFeatureRegistry's own Available/Planned truth. A feature could
 * flip Planned -> Available and never gain a customer-reachable destination,
 * and nothing would fail — exactly the silent-forever-invisible failure this
 * registry exists to catch (see
 * tests/Feature/Navigation/NavigationDispositionConsistencyTest.php).
 *
 * THIS IS NOT A SECOND AUTHORIZATION SYSTEM. It carries no permission,
 * entitlement or route decision of its own — CustomerMenuBuilder,
 * MenuEntitlements and each destination's own controller remain the only
 * authorities on whether an actor may actually reach a feature. This
 * registry only answers "was a deliberate navigation home chosen for this
 * feature", never "may this actor open it now".
 *
 * NOT EVERY AVAILABLE FEATURE NEEDS TopLevel. AiCooBasic is intentionally
 * BusinessHomeOnly (CustomerMenuBuilder::businessFrame()'s own docblock:
 * "No separate Advisor entry (owner decision)"); ProspectOutreach is
 * intentionally AccountFrameOnly (Workspace-scoped, Agency-only, never a
 * Business sidebar entry). Both are still deliberate choices, recorded here
 * exactly like every TopLevel entry.
 *
 * COORDINATION NOTE (customer-navigation-coherence-pass, 2026-09-28 —
 * updated 2026-09-29 once SEO Contract 18 Sub-slice H merged to main as
 * PR #409, then #412/#415 etc.). SeoBasicVisibility and SeoModule are now
 * Available and carry the TopLevel "SEO" parent group
 * (CustomerMenuBuilder::seoMenuItem()) that folds in the former standalone
 * "Get found" entry as one of its children — GoogleBusinessProfileModule's
 * own disposition below was updated from TopLevel to WithinModule to match,
 * since it is no longer reachable as its own sidebar item.
 */
final class NavigationDispositionRegistry
{
    private const DISPOSITIONS = [
        // Opportunities — the CRM sales board (CustomerMenuBuilder::businessFrame()).
        PlatformFeature::Crm->value => NavigationDisposition::TopLevel,
        // Conversations — the one Business-scoped inbox entry.
        PlatformFeature::Conversations->value => NavigationDisposition::TopLevel,
        PlatformFeature::Automations->value => NavigationDisposition::TopLevel,
        // Agency AI Prospecting — Workspace-scoped, Agency-only, reached
        // exclusively from the account frame's "Prospecting" entry; never a
        // Business sidebar item (PlatformFeatureRegistry's own Workspace-scope
        // note on this key).
        PlatformFeature::ProspectOutreach->value => NavigationDisposition::AccountFrameOnly,
        PlatformFeature::WebsiteGeneration->value => NavigationDisposition::TopLevel,
        // "Get found" — folded into the SEO group's children
        // (CustomerMenuBuilder::seoMenuItem()) since Contract 18 Sub-slice H;
        // no longer its own sidebar entry.
        PlatformFeature::GoogleBusinessProfileModule->value => NavigationDisposition::WithinModule,
        PlatformFeature::Calendar->value => NavigationDisposition::TopLevel,
        // SEO (Contract 18 Sub-slice H) — the "SEO" parent group, folding in
        // Get found. seo_basic_visibility gates the parent and its Core+
        // children (Overview, Search keywords); seo_module additionally
        // gates the Growth+ children (Site Audit, Citations, Reviews).
        PlatformFeature::SeoBasicVisibility->value => NavigationDisposition::TopLevel,
        PlatformFeature::SeoModule->value => NavigationDisposition::TopLevel,
        // AI COO Basic — Business Home's "What we notice" line and its
        // "Explain this change" action. Deliberately no sidebar entry (owner
        // decision, CustomerMenuBuilder::businessFrame()'s own docblock).
        PlatformFeature::AiCooBasic->value => NavigationDisposition::BusinessHomeOnly,
        PlatformFeature::PackagesProducts->value => NavigationDisposition::TopLevel,
        PlatformFeature::PaymentsContracts->value => NavigationDisposition::TopLevel,
    ];

    public static function has(string $featureKey): bool
    {
        return array_key_exists($featureKey, self::DISPOSITIONS);
    }

    public static function dispositionFor(string $featureKey): ?NavigationDisposition
    {
        return self::DISPOSITIONS[$featureKey] ?? null;
    }

    /**
     * @return array<string, NavigationDisposition>
     */
    public static function all(): array
    {
        return self::DISPOSITIONS;
    }
}
