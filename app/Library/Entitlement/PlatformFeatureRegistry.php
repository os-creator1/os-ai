<?php

namespace App\Library\Entitlement;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\PlatformFeatureAvailability;
use App\Enums\Entitlement\PlatformFeatureScope;

/**
 * Code-backed, static implementation-availability authority (RFC-004 §11),
 * checked strictly before any plan mapping or Workspace override is even
 * consulted. Distinct from PlatformFeature (identity) — a known feature key
 * is never, by itself, proof it may execute.
 *
 * AVAILABILITY below is locked to docs/automation/RFC-004-M1-CONTRACT.md §6's
 * evidence matrix, gathered by direct repository inspection at contract-
 * drafting time — Crm/Conversations/Automations confirmed via an existing
 * Contact/ChatBox/Automations controller each; every other case had no
 * direct executable implementation found anywhere in this repository at
 * that time. Only a future code deploy may change a value here.
 *
 * ProspectOutreach flipped to Available by the Agency AI Prospecting
 * foundation pass: a real, executable, Workspace-scoped controller/routes/
 * persistence now exists (App\Http\Controllers\Customer\Workspace\
 * AgencyProspectingController and its agency_prospect_* schema), matching
 * the exact evidentiary bar Crm/Conversations/Automations were already
 * held to. This flip does not, by itself, claim the automatic AI responder
 * engine or Workspace-level provider sending are implemented — both remain
 * explicitly deferred (see the foundation pass's own report); it only
 * reflects that the feature now has a real, reachable, Agency-gated
 * surface, exactly as this class's own contract requires.
 */
final class PlatformFeatureRegistry
{
    private const AVAILABILITY = [
        PlatformFeature::Crm->value => PlatformFeatureAvailability::Available,
        PlatformFeature::Conversations->value => PlatformFeatureAvailability::Available,
        PlatformFeature::Automations->value => PlatformFeatureAvailability::Available,
        PlatformFeature::ProspectOutreach->value => PlatformFeatureAvailability::Available,
        // Website Generation + Hosting Slice A implementation pass:
        // flipped Planned -> Available, mirroring the exact evidentiary
        // bar ProspectOutreach was already held to (a real, executable,
        // Business-scoped controller/routes/persistence now exists —
        // App\Http\Controllers\Customer\Business\WebsiteController and
        // its websites/website_pages/website_revisions/website_assets
        // schema). Plan packaging for this feature already existed
        // before this flip (2026_08_13_120007_seed_workspace_plan_catalog_and_features.php),
        // seeded independently of this availability lock.
        PlatformFeature::WebsiteGeneration->value => PlatformFeatureAvailability::Available,
        // Google Business Profile Slice A implementation pass (contract
        // §2.2): registered Available on arrival, meeting the exact
        // evidentiary bar ProspectOutreach and WebsiteGeneration were each
        // held to — a real, executable, Business-scoped controller/routes/
        // persistence now exists (App\Http\Controllers\Customer\Business\
        // GoogleBusinessProfileController and its
        // business_google_connections / business_google_locations /
        // business_google_operations schema). Unlike WebsiteGeneration,
        // this feature also needs NEW plan packaging: it is Growth +
        // Agency only, seeded by
        // 2026_09_09_120004_seed_google_business_profile_plan_packaging.php,
        // and Core is deliberately excluded.
        PlatformFeature::GoogleBusinessProfileModule->value => PlatformFeatureAvailability::Available,
        // Contract 15.E: public UUID scheduler, Location-local Contact identity,
        // canonical booking engine and authenticated calendar now form one flow.
        // Core/Growth/Agency packaging was seeded by the original catalog
        // migration; this deploy does not rewrite mutable plan features.
        PlatformFeature::Calendar->value => PlatformFeatureAvailability::Available,
        // Forms V1 domain foundation — flipped Planned -> Available, meeting
        // the exact evidentiary bar every flip above is held to: a real,
        // executable, Business-scoped, STANDALONE surface now exists
        // (App\Http\Controllers\Customer\Business\FormsController and
        // FormSubmissionsController, the public App\Http\Controllers\Public\
        // PublicFormController, over FormManager, FormSubmissionService and
        // FormSubmissionReader, with the forms / form_versions /
        // form_deployments / form_submissions schema). It is authorized by
        // THIS feature, never by WebsiteGeneration. Plan packaging already
        // existed for Core, Growth and Agency
        // (2026_08_13_120007_seed_workspace_plan_catalog_and_features.php) and
        // is unchanged, as is the usage classification row, so no packaging
        // migration is needed; an unassigned, inactive, suspended or
        // override-denied Workspace is still refused by EntitlementManager
        // exactly as before.
        PlatformFeature::Forms->value => PlatformFeatureAvailability::Available,        // Unified Business Home and COO Decision Engine contract §17, slice
        // AI-3: flipped Planned -> Available, meeting the same evidentiary
        // bar as every flip above — a real, executable, Business-scoped
        // implementation now exists (App\Jobs\Coo\GenerateCooInsight through
        // the AI-1 gateway, the coo_insights cache, the Business Home "What
        // we notice" line and the "Explain this change" route). Plan
        // packaging already existed for Core, Growth and Agency
        // (2026_08_13_120007_seed_workspace_plan_catalog_and_features.php)
        // and is unchanged; an unassigned, inactive or suspended plan is
        // still denied by EntitlementManager, and no trial state exists.
        PlatformFeature::AiCooBasic->value => PlatformFeatureAvailability::Available,
        // Implementation Contract 18, Sub-slice H — the entitlement flip,
        // after Sub-slice A (foundation/Overview/readers) and every
        // sub-slice it fronts that is actually built were merged and
        // independently verified, meeting the same evidentiary bar every
        // flip above is held to: a real, executable, Business-scoped
        // surface now exists for both keys
        // (App\Http\Controllers\Customer\Business\{SeoController,
        // SeoKeywordsController,SeoCitationController,SeoReviewsController,
        // SeoAuditController} and their seo_keywords/seo_citations/
        // seo_location_review_links/seo_review_requests/seo_audit_runs/
        // seo_audit_findings schema). Corrected per independent review —
        // an earlier revision of this comment wrongly stated Sub-slice B
        // was unbuilt:
        //   - Sub-slice B (the GBP connection product discriminator, PR
        //     #384) IS built and merged: GoogleConnectionProduct, the
        //     product column on business_google_connections, product-
        //     scoped GBP connection access, and a product-carrying OAuth
        //     state, with a reserved (unused) SearchConsole discriminator
        //     value.
        //   - D (Keywords), E (Citations), F (Reviews) and G (technical/
        //     Website SEO audit) are built.
        //   - Sub-slice C (Search Console) is the one NOT built: its
        //     external Google gates (OD-4 verification, OD-5 data-use
        //     sign-off) remain unresolved, and Sub-slice B being merged
        //     does not itself authorize C.
        // So H performs only the final availability/navigation/
        // integration flip for what is actually built, without pretending
        // Search Console exists: no Search Console file, route or config
        // exists, so nothing beyond the built sub-slices is exposed by
        // this flip. Plan packaging already existed for both keys
        // (2026_08_13_120007_seed_workspace_plan_catalog_and_features.php:
        // SeoBasicVisibility Core+Growth+Agency, SeoModule Growth+Agency
        // only) and is unchanged; no new packaging or classification
        // migration is needed (contract §10.3).
        PlatformFeature::SeoBasicVisibility->value => PlatformFeatureAvailability::Available,
        PlatformFeature::AdsBasicVisibility->value => PlatformFeatureAvailability::Available,
        PlatformFeature::SeoModule->value => PlatformFeatureAvailability::Available,
        PlatformFeature::GoogleAdsModule->value => PlatformFeatureAvailability::Available,
        // Meta Ads V1 (contract 24 §8, M10): ads_module is the provider-neutral
        // full Ads capability; google_ads_module / meta_ads_module stay valid
        // legacy synonyms, all resolved by App\Library\Ads\AdsFeatureAccess.
        PlatformFeature::AdsModule->value => PlatformFeatureAvailability::Available,
        PlatformFeature::MetaAdsModule->value => PlatformFeatureAvailability::Available,
        // Agency V1 completion — Planned -> Available, meeting the evidentiary
        // bar every flip here is held to: a real, executable, Workspace-scoped
        // surface now exists (AgencyWhiteLabelController over
        // AgencyWhiteLabelManager and its agency_white_label_settings storage,
        // rendered into the signed-in client chrome by
        // ClientWorkspaceBrandResolver/ClientChromeBrand). It is Workspace-
        // scoped like ProspectOutreach — branding belongs to the Agency
        // Workspace, not to any one Business — so it is decided through
        // EntitlementManager::decideForWorkspace(), the existing entitlement
        // architecture, and never appears in a Business's feature list. Plan
        // packaging already existed (Agency only,
        // 2026_08_13_120007_seed_workspace_plan_catalog_and_features.php) and
        // is unchanged. What is NOT part of this flip: a custom branded
        // domain and the host-resolved login brand (AgencyBrandSource stays
        // unbound — see docs/product/implementation-contracts/22-AGENCY-WHITE-LABEL.md).
        PlatformFeature::WhiteLabel->value => PlatformFeatureAvailability::Available,
        PlatformFeature::AgencyPackageCapabilities->value => PlatformFeatureAvailability::Planned,
        // Implementation Contract 16, Sub-slice E — the FINAL flip,
        // Planned -> Available, meeting the exact evidentiary bar every flip
        // above was held to: a real, executable, Business-scoped surface now
        // exists (App\Http\Controllers\Customer\Business\CatalogItemsController
        // and CatalogLocationOffersController, their catalog routes and views,
        // over CatalogItemManager, CatalogItemLocationOverrideManager and
        // CatalogItemPricingResolver). It was Planned through Sub-slices A-D,
        // which built the schema, inert entitlement identity and domain
        // services with no customer HTTP surface, and was flipped only after
        // the surface's full authorization chain was proven: tenancy, the
        // packages_products capability, this entitlement, and for
        // Location-scoped routes LocationAccessGuard. Plan packaging already
        // existed for Core, Growth and Agency (Sub-slice A,
        // 2026_09_23_100005_seed_packages_products_plan_packaging.php) and is
        // unchanged; an unassigned, inactive, suspended or override-denied
        // Workspace is still refused by EntitlementManager exactly as before.
        PlatformFeature::PackagesProducts->value => PlatformFeatureAvailability::Available,
        // Implementation Contract 17, Sub-slice G — the FINAL flip,
        // Planned -> Available, after a review correction closed the two
        // gaps the first G pass left: the Global Search foundation
        // (Blueprint §7/§24 — App\Library\Search, four bounded sources, no
        // new index/schema) and the Activity Center's READ-TIME
        // authorization (§12.G — DocumentActivityCenterReader re-derives
        // tenancy, the payments_contracts capability, the entitlement,
        // document existence and LocationAccessGuard fresh on every read,
        // through the existing platform_database_notifications substrate,
        // never the legacy notifications table). Flipped only once nav,
        // timeline, the corrected Activity Center and Global Search all
        // passed their focused tests and the end-to-end acceptance path
        // (PaymentsContractsAcceptanceTest) passed again against the real,
        // unmocked authenticated and public routes. Plan packaging already
        // existed for Core, Growth and Agency (Sub-slice A,
        // 2026_09_25_100012_seed_payments_contracts_plan_packaging.php) and
        // is unchanged; an unassigned, inactive, suspended or override-denied
        // Workspace is still refused by EntitlementManager exactly as before.
        PlatformFeature::PaymentsContracts->value => PlatformFeatureAvailability::Available,
        // SEO Keyword Rank Tracking V1: a real Business-scoped surface exists
        // (SeoRankTargetsController over SeoRankTargetManager and the budgeted
        // check pipeline). Availability is code-level only: the provider master
        // switch (seo.rank_tracking.enabled) still defaults OFF, so no paid call
        // can happen until the operator enables it.
        PlatformFeature::SeoRankTracking->value => PlatformFeatureAvailability::Available,
    ];

    /**
     * Correction 1 — the single source of feature-scope truth. Every key
     * absent here defaults to Business scope (the overwhelming majority
     * and every case that predates this correction); ProspectOutreach and
     * WhiteLabel (branding is the Agency Workspace's own) are the
     * Workspace-scoped exceptions. Never consulted for identity
     * (PlatformFeature) or implementation-availability (AVAILABILITY
     * above) — a third, independent, orthogonal concern.
     */
    private const SCOPE = [
        PlatformFeature::ProspectOutreach->value => PlatformFeatureScope::Workspace,
        PlatformFeature::WhiteLabel->value => PlatformFeatureScope::Workspace,
    ];

    public static function isKnown(string $featureKey): bool
    {
        return PlatformFeature::tryFrom($featureKey) !== null;
    }

    public static function isAvailable(string $featureKey): bool
    {
        return self::isKnown($featureKey)
            && (self::AVAILABILITY[$featureKey] ?? null) === PlatformFeatureAvailability::Available;
    }

    public static function isWorkspaceScoped(string $featureKey): bool
    {
        return (self::SCOPE[$featureKey] ?? PlatformFeatureScope::Business) === PlatformFeatureScope::Workspace;
    }

    public static function isBusinessScoped(string $featureKey): bool
    {
        return ! self::isWorkspaceScoped($featureKey);
    }
}
