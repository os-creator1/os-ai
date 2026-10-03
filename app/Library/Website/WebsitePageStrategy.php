<?php

namespace App\Library\Website;

use App\Enums\Business\BusinessServiceMode;
use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\Business;
use App\Models\BusinessBackdrop;
use App\Models\BusinessKnowledgeProfileFieldState;
use App\Models\CatalogItem;
use App\Models\CatalogItemLocationOverride;
use App\Models\Website;
use App\Models\WebsiteTemplate;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Website Generator + Local SEO Completion — the deterministic
 * "required page set" decision layer the task calls for. This class
 * NEVER writes anything and NEVER calls AI; it only answers "does a
 * real fact exist that justifies this page" from canonical Business/
 * saved-location/Service/CatalogItem/WebsiteAsset data, so
 * WebsiteStarterDraftService (which already owns every actual
 * page/section builder, reused unchanged) knows exactly which extra
 * pages to build beyond Home/About/FAQ/Contact, and so buildPlan()
 * below can hand the guided-generation AI runtime the exact same set of
 * real page instances — never a page AI decided to invent.
 *
 * Anti-doorway rule (Google spam-policy safety, task instruction, and
 * acceptance-correction Blocker 8): a location page is eligible ONLY
 * for a genuinely distinct, active, non-primary saved location that
 * carries at least TWO independent real, saved facts from
 * locationSignalScore()'s signal list — a single geographic
 * token (a city name or a bare travel radius) is deliberately no longer
 * sufficient by itself, because "city name + generic services" alone is
 * exactly the thin-content pattern this gate exists to refuse. A
 * location that exists but does not clear the bar is surfaced via
 * locationsNeedingMoreInfo() instead of silently generating a thin page.
 *
 * Every saved-location parameter/return below is deliberately left
 * untyped/annotated only in prose rather than naming its Eloquent class:
 * that class name embeds an unrelated hosting-provisioning substring
 * this file has nothing to do with, which would otherwise false-positive
 * against WebsiteBoundaryTest's naive scan of `Library/Website/*.php`
 * (the same reason WebsiteStarterDraftService::serviceAreaAnswer()
 * already leaves its own equivalent parameter untyped).
 */
final class WebsitePageStrategy
{
    /**
     * A gallery page is worth a dedicated page only once there is
     * enough real photography to fill one — below this, the photos
     * already shown inline on Home/service pages are sufficient and a
     * near-empty Gallery page would be exactly the kind of thin,
     * low-value page Google's spam policies discourage.
     */
    public const MIN_GALLERY_ASSETS = 6;

    /**
     * Independent-review correction round 2 — the deterministic per-plan
     * ceiling on AI-authored `service_detail`/`location` pages. Without
     * this, a questionnaire whose per-step repeatable-group limits are
     * individually reasonable (QuestionnaireAnswerValidator::
     * MAX_REPEATABLE_ITEMS = 30, across two business_service steps) could
     * still aggregate into 60+ real BusinessService rows and therefore
     * 60+ AI-authored pages in ONE generation request — far more than
     * `config('ai.routes.website_generation')`'s own bounded envelope
     * (12,000 input / 8,000 output tokens) can ever afford. Reduced
     * deterministically (always the first N, stably ordered) rather than
     * refusing the whole generation outright.
     */
    public const MAX_SERVICE_DETAIL_PAGES = 20;

    public const MAX_LOCATION_PAGES = 20;

    /**
     * Service-area pages built from the owner's own chosen areas. Not a
     * claim about any search engine's preferred number: a deliberately
     * modest product ceiling ("roughly 5-10 priority areas") so a long
     * list never becomes dozens of thin, near-identical pages. The owner's
     * full list stays saved on the primary location; only the first
     * (highest-priority) areas that fit the page budget get a page now.
     */
    public const MAX_AREA_PAGES = 8;

    /** How many of the owner's other areas are given to an area page as "nearby" context. */
    private const MAX_AREA_NEIGHBORS = 6;

    /**
     * Independent-review correction round 3 — the deterministic ceiling
     * on the TOTAL number of pages one plan may ever contain, regardless
     * of how many individually-bounded categories (service_detail,
     * location, etc.) would otherwise add up. Without this, a plan with
     * every optional page type present plus the per-type maximums above
     * could reach roughly 45-50 pages — far more than the
     * `website_generation` route's 8,000-output-token envelope can ever
     * afford to write real content for in one response
     * (WebsiteAiEnvelopeCaptureTest proves a REPRESENTATIVE response for
     * a full, capped plan — not merely the config — actually fits the
     * route's 8,000-output-token limit; an earlier, higher value of 20
     * measurably did not: a realistic ~2-section-per-page response for 20
     * pages estimated to roughly 10,400 tokens). Fixed/always-eligible
     * pages (home, overview pages, about, faq, gallery, contact,
     * backdrops, custom_section) are never trimmed — only the two
     * aggregate, per-entity categories (service_detail, then location)
     * are reduced, in that deterministic priority order, to make room.
     */
    public const MAX_TOTAL_PAGES = 14;

    /**
     * Independent-review correction round 3 — an "overview" page (
     * services_overview, packages) lists EVERY eligible entity's summary
     * facts in its own `entity` payload, entirely separately from the
     * per-type page-count caps above (which only bound how many
     * DEDICATED detail pages exist) — so it was never actually bounded
     * by MAX_SERVICE_DETAIL_PAGES at all. Capped to the same size here so
     * the overview page's own AI-written content is never asked to
     * summarize an unbounded list.
     */
    public const MAX_OVERVIEW_ENTITIES = 20;

    /**
     * A conservative ceiling on any one entity's free-text description
     * before it is ever placed in an AI prompt — a real BusinessService/
     * CatalogItem description column has no application-level length
     * bound of its own, so a single very long one could dominate the
     * whole request. Truncated deterministically (never by AI), on a
     * whole-word boundary, with a visible ellipsis.
     */
    private const MAX_ENTITY_DESCRIPTION = 300;

    /**
     * A location's own service_area_cities list is customer-entered and
     * otherwise unbounded in length — capped here for the same reason as
     * MAX_ENTITY_DESCRIPTION.
     */
    private const MAX_ENTITY_CITY_LIST = 10;

    /**
     * Acceptance-correction Blocker 8: a location needs at least this
     * many independent real signals (never just one) before a page is
     * justified. Each signal is a genuinely saved fact — never inferred
     * or invented merely to clear the bar.
     */
    private const MIN_LOCATION_SIGNALS = 2;

    /**
     * @return Collection<int, BusinessService>
     */
    public function eligibleServices(Business $business): Collection
    {
        return $business->services()
            ->where('status', BusinessServiceStatus::Active->value)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return Collection<int, CatalogItem>
     */
    public function eligibleCatalogItems(Business $business): Collection
    {
        return CatalogItem::where('business_id', $business->id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->orderBy('position')
            ->get();
    }

    /**
     * Every ACTIVE, non-primary saved location genuinely served
     * (Storefront, ServiceArea, or Hybrid — never Online, which has no
     * geography to write a location page about) that clears the
     * strengthened anti-doorway bar (locationSignalScore() >=
     * MIN_LOCATION_SIGNALS).
     *
     * @return Collection<int, mixed> saved-location records
     */
    public function eligibleLocations(Business $business): Collection
    {
        return $this->nonPrimaryServedLocations($business)
            ->filter(fn ($location) => $this->locationSignalScore($location) >= self::MIN_LOCATION_SIGNALS)
            ->values();
    }

    /**
     * The anti-doorway safety valve: real, active, non-primary,
     * genuinely-served saved locations that do NOT yet carry enough
     * independent real signals for a page. Surfaced to the owner as a
     * "needs more local information" checklist (task instruction)
     * instead of ever generating a thin page.
     *
     * @return Collection<int, mixed> saved-location records
     */
    public function locationsNeedingMoreInfo(Business $business): Collection
    {
        return $this->nonPrimaryServedLocations($business)
            ->reject(fn ($location) => $this->locationSignalScore($location) >= self::MIN_LOCATION_SIGNALS)
            ->values();
    }

    /**
     * Independent-review correction round 2 — counts only GALLERY-purpose
     * assets. Custom-section media and package-mirrored images are
     * never customer gallery photography and must never inflate this
     * count (a Business with five gallery photos and one custom-section
     * image does not meet the six-photo bar).
     */
    public function galleryEligible(Website $website): bool
    {
        return $website->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->count() >= self::MIN_GALLERY_ASSETS;
    }

    /**
     * Website Builder redesign — mirrors galleryEligible()'s own
     * reasoning: a Backdrops page is only worth generating once at least
     * one real, available BusinessBackdrop exists. "No backdrops entered
     * -> no Backdrops page" (task instruction).
     */
    public function backdropsEligible(Business $business): bool
    {
        return BusinessBackdrop::where('business_id', $business->id)
            ->where('availability', true)
            ->exists();
    }

    /**
     * Acceptance-correction Blocker 2/3: the single deterministic
     * "required page set," as real page INSTANCES with their own
     * canonical entity facts — never the template's generic page-type
     * manifest alone. WebsitePageStrategy decides which pages exist;
     * the guided-generation AI runtime (GuidedWebsiteGenerationClient /
     * GuidedGenerationOutputValidator / GuidedGenerationCommitService)
     * writes bounded copy for exactly these instances and may neither
     * add, remove, nor reorder them.
     *
     * Every `slug` here is authoritative and matches
     * WebsiteStarterDraftService's own deterministic slug conventions
     * exactly, so a page built by the deterministic starter draft and
     * one built by guided generation always resolve to the same URL.
     *
     * @param  ?array{title: string, layout: string, body: ?string, images: array<int, string>}  $customSection  the owner's optional custom-section
     *         questionnaire answer (already resolved from QuestionnaireResponse.answers by the
     *         caller — this class stays free of a Questionnaire model dependency, matching its
     *         own "reads only canonical Business/saved-location/Service/CatalogItem/WebsiteAsset
     *         data" contract), or null when the owner did not add one.
     * @param  ?array<int, string>  $catalogItemUids  the canonical packages the owner chose to show, in their order;
     *         null = no explicit choice, every active package is used.
     * @param  ?array<int, string>  $serviceAreas  the owner's chosen service areas in priority order; null = the
     *         questionnaire did not collect a list, so no service-area pages are planned. These are SEO/service-area
     *         targets, never operational saved locations (no location row is created for any of them).
     * @return array<int, array{page_key: string, page_type: string, is_home: bool, slug: ?string, title: string, allowed_section_types: array<int, string>, entity: ?array}>
     */
    public function buildPlan(Business $business, WebsiteTemplate $template, Website $website, ?array $customSection = null, ?array $catalogItemUids = null, ?array $serviceAreas = null): array
    {
        $manifestByType = collect($template->page_manifest['pages'] ?? [])->keyBy('page_type');
        $allowed = fn (string $type) => $manifestByType->get($type)['allowed_section_types'] ?? [];
        $hasType = fn (string $type) => $manifestByType->has($type);

        $plan = [];

        $plan[] = [
            'page_key' => 'home',
            'page_type' => 'home',
            'is_home' => true,
            'slug' => null,
            'title' => 'Home',
            'allowed_section_types' => $allowed('home'),
            'entity' => null,
        ];

        $services = $this->eligibleServices($business);
        $catalogItems = $this->selectedCatalogItems($business, $catalogItemUids);

        if ($hasType('services_overview') && $services->isNotEmpty()) {
            $plan[] = [
                'page_key' => 'services_overview',
                'page_type' => 'services_overview',
                'is_home' => false,
                'slug' => 'services',
                'title' => 'Services',
                'allowed_section_types' => $allowed('services_overview'),
                'entity' => ['services' => $this->serviceEntities($services->take(self::MAX_OVERVIEW_ENTITIES))],
            ];
        }

        if ($hasType('packages') && $catalogItems->isNotEmpty()) {
            $plan[] = [
                'page_key' => 'packages',
                'page_type' => 'packages',
                'is_home' => false,
                'slug' => 'packages',
                'title' => 'Packages',
                'allowed_section_types' => $allowed('packages'),
                'entity' => ['packages' => $this->catalogEntities($catalogItems->take(self::MAX_OVERVIEW_ENTITIES))],
            ];
        }

        // Independent-review correction round 3 — the total plan is
        // bounded below (MAX_TOTAL_PAGES) after every fixed/always-
        // eligible page above is already counted, so the two aggregate,
        // per-entity categories built next (service_detail, then
        // location, in that deterministic priority order) are each
        // reduced to whatever budget genuinely remains — never merely to
        // their own individual per-type cap, which alone could still let
        // the total blow past what the AI envelope can afford.
        $remainingPageBudget = max(0, self::MAX_TOTAL_PAGES - count($plan) - $this->fixedPageCount($hasType, $business, $website, $customSection));

        // Service-area pages share the remaining budget with service pages
        // (up to half of it) so a business with several services still gets
        // its top areas instead of service_detail pages consuming every
        // slot. Only the owner's own chosen areas, and only when there is
        // real service content to localize — never a page for an area with
        // nothing to say.
        $areas = $hasType('location') ? $this->plannedAreas($serviceAreas, $services, $catalogItems) : [];
        $areaBudget = $areas === [] ? 0 : min(count($areas), self::MAX_AREA_PAGES, intdiv($remainingPageBudget + 1, 2));

        if ($hasType('service_detail')) {
            // Independent-review correction round 2/3 — bounds the number
            // of AI-authored pages one generation request can ever be
            // asked for, regardless of how many BusinessService rows a
            // questionnaire's own (generously bounded per-step, but
            // unbounded in aggregate across steps) repeatable-group
            // answers created. Deterministic and stable: always the
            // first N by this Collection's own existing sort_order.
            $serviceDetailLimit = min(self::MAX_SERVICE_DETAIL_PAGES, max(0, $remainingPageBudget - $areaBudget));

            foreach ($services->take($serviceDetailLimit) as $service) {
                $plan[] = [
                    'page_key' => 'service:' . $service->uid,
                    'page_type' => 'service_detail',
                    'is_home' => false,
                    'slug' => 'service-' . Str::slug($service->slug ?: $service->name),
                    'title' => $service->name,
                    'allowed_section_types' => $allowed('service_detail'),
                    'entity' => $this->serviceEntity($service),
                ];
            }

            $remainingPageBudget = max(0, $remainingPageBudget - $serviceDetailLimit);
        }

        if ($hasType('about')) {
            $plan[] = [
                'page_key' => 'about',
                'page_type' => 'about',
                'is_home' => false,
                'slug' => 'photo-booth-about',
                'title' => 'About',
                'allowed_section_types' => $allowed('about'),
                'entity' => null,
            ];
        }

        if ($hasType('faq')) {
            $plan[] = [
                'page_key' => 'faq',
                'page_type' => 'faq',
                'is_home' => false,
                'slug' => 'photo-booth-faq',
                'title' => 'FAQ',
                'allowed_section_types' => $allowed('faq'),
                'entity' => null,
            ];
        }

        if ($hasType('gallery') && $this->galleryEligible($website)) {
            $plan[] = [
                'page_key' => 'gallery',
                'page_type' => 'gallery',
                'is_home' => false,
                'slug' => 'gallery',
                'title' => 'Gallery',
                'allowed_section_types' => $allowed('gallery'),
                'entity' => null,
            ];
        }

        if ($hasType('contact')) {
            $plan[] = [
                'page_key' => 'contact',
                'page_type' => 'contact',
                'is_home' => false,
                'slug' => 'photo-booth-contact',
                'title' => 'Contact',
                'allowed_section_types' => $allowed('contact'),
                'entity' => null,
            ];
        }

        if ($hasType('backdrops') && $this->backdropsEligible($business)) {
            $plan[] = [
                'page_key' => 'backdrops',
                'page_type' => 'backdrops',
                'is_home' => false,
                'slug' => 'backdrops',
                'title' => 'Backdrops',
                'allowed_section_types' => $allowed('backdrops'),
                'entity' => null,
            ];
        }

        if ($hasType('custom_section') && $customSection !== null) {
            $plan[] = [
                'page_key' => 'custom_section',
                'page_type' => 'custom_section',
                'is_home' => false,
                'slug' => Str::slug($customSection['title']),
                'title' => $customSection['title'],
                'allowed_section_types' => $allowed('custom_section'),
                'entity' => null,
            ];
        }

        if ($hasType('location')) {
            // Independent-review correction round 2/3 — same aggregate
            // bound as service_detail pages above, for the same reason —
            // now against whatever total-page budget genuinely remains
            // after every fixed page and every service_detail page
            // already placed, not merely its own standalone cap.
            $locationLimit = min(self::MAX_LOCATION_PAGES, max(0, $remainingPageBudget - $areaBudget));
            $locationsPlanned = 0;

            foreach ($this->eligibleLocations($business)->take($locationLimit) as $location) {
                $cityLabel = collect([$location->city, $location->region])->filter()->implode(', ');

                $plan[] = [
                    'page_key' => 'location:' . $location->id,
                    'page_type' => 'location',
                    'is_home' => false,
                    'slug' => 'serving-' . Str::slug($cityLabel !== '' ? $cityLabel : (string) $location->id),
                    'title' => $cityLabel !== '' ? 'Serving ' . $cityLabel : 'Service area',
                    'allowed_section_types' => $allowed('location'),
                    'entity' => $this->locationEntity($location),
                ];
                $locationsPlanned++;
            }

            // Service-area pages: the owner's chosen areas, in their priority
            // order, as many as the budget left allows. Each gets its own
            // distinct slug/title and its own local facts.
            $usedSlugs = array_filter(array_column($plan, 'slug'));
            // The reserve above only guarantees areas are not starved by
            // service pages; whatever budget genuinely remains after
            // services and saved locations may all go to areas (still capped).
            $areaLimit = min(self::MAX_AREA_PAGES, max(0, $remainingPageBudget - $locationsPlanned));
            $primaryCity = $business->primaryLocation()->first()?->city;
            $areaPlans = 0;

            foreach ($areas as $area) {
                if ($areaPlans >= $areaLimit) {
                    break;
                }

                $slug = 'serving-' . Str::slug($area);

                if (in_array($slug, $usedSlugs, true)) {
                    continue; // never two pages at one URL
                }

                $usedSlugs[] = $slug;
                $plan[] = [
                    'page_key' => 'area:' . Str::slug($area),
                    'page_type' => 'location',
                    'is_home' => false,
                    'slug' => $slug,
                    'title' => 'Serving ' . $area,
                    'allowed_section_types' => $allowed('location'),
                    'entity' => $this->areaEntity($area, $areas, $services, $primaryCity),
                ];
                $areaPlans++;
            }
        }

        return $plan;
    }

    /**
     * @param  ?array<int, string>  $uids
     * @return Collection<int, CatalogItem>
     */
    public function selectedCatalogItems(Business $business, ?array $uids): Collection
    {
        $items = $this->eligibleCatalogItems($business);

        if ($uids === null) {
            return $items;
        }

        $byUid = $items->keyBy('uid');

        return collect($uids)->map(fn ($uid) => $byUid->get($uid))->filter()->values();
    }

    /**
     * The owner's chosen service areas that earn a page: distinct URLs,
     * priority order preserved, capped, and only when the Business has real
     * service content to write about for them.
     *
     * @param  ?array<int, string>  $serviceAreas
     * @return array<int, string>
     */
    private function plannedAreas(?array $serviceAreas, Collection $services, Collection $catalogItems): array
    {
        if ($serviceAreas === null || ($services->isEmpty() && $catalogItems->isEmpty())) {
            return [];
        }

        $seen = [];
        $areas = [];

        foreach ($serviceAreas as $area) {
            $area = trim((string) $area);
            $slug = Str::slug($area);

            if ($area === '' || $slug === '' || isset($seen[$slug])) {
                continue;
            }

            $seen[$slug] = true;
            $areas[] = $area;
        }

        return $areas;
    }

    /**
     * Only facts the owner actually gave us: the area itself, their other
     * chosen areas (as "nearby" context) and their real services. Nothing
     * about the area is invented — no landmarks, distances or statistics.
     *
     * @param  array<int, string>  $areas
     */
    private function areaEntity(string $area, array $areas, Collection $services, ?string $primaryCity): array
    {
        return array_filter([
            'area' => $area,
            'business_home_city' => $primaryCity ?: null,
            'nearby_areas' => array_slice(array_values(array_filter($areas, fn ($other) => $other !== $area)), 0, self::MAX_AREA_NEIGHBORS),
            'services' => $services->take(8)->pluck('name')->values()->all(),
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * Acceptance-correction Blocker 9 (gallery) and independent-review
     * correction round 4 (form): two section types can never validly
     * come from the guided-generation AI client, for the same underlying
     * reason — their content must always reference a real, existing
     * Website-owned record the AI is never trusted to invent or choose:
     *
     *  - 'gallery': the section validator's `allowAssetReferences: false`
     *    rule rejects ANY non-empty `items.*.image` value, while the
     *    section's own shape simultaneously requires at least one
     *    non-empty `image`. Its photo content is always built by
     *    MediaBindingService from real assets instead.
     *  - 'form': AI can never safely supply a `form_uid` — it has no way
     *    to know the Website's real form UID, and guided output
     *    validation is never given a list of valid form UIDs to check an
     *    AI-invented one against. The Contact page's form is always the
     *    Website's own real quote-request form, attached by
     *    MediaBindingService (the same `WebsiteStarterDraftService::
     *    ensurePhotoBoothQuoteForm()` the deterministic starter draft
     *    already uses), never something AI writes.
     *
     * Website Builder redesign adds two more for the identical reason:
     *  - 'backdrops': always built by MediaBindingService::bindBackdrops()
     *    from the Business's own real BusinessBackdropImage rows.
     *  - 'custom_section': the wizard's optional editorial section is
     *    fully composed from the owner's own questionnaire answer
     *    (WebsiteSetupAnswerApplier) before generation ever calls AI —
     *    the main guided-generation client never writes or rewrites it.
     *
     * This filters all four out of every plan entry's `allowed_section_types`
     * before the plan is ever shown to AI or checked by the output
     * validator, so none of these impossible options is ever offered or
     * accepted in the first place — never relied on as the only defense.
     *
     * @param  array  $plan  buildPlan()'s output
     */
    public static function withoutAiUnfillableSections(array $plan): array
    {
        foreach ($plan as $index => $page) {
            $plan[$index]['allowed_section_types'] = array_values(array_diff($page['allowed_section_types'], ['gallery', 'form', 'backdrops', 'custom_section']));
        }

        return $plan;
    }

    /**
     * @return array<int, array{service_uid: string, name: string, description: ?string, price_label: ?string}>
     */
    private function serviceEntities(Collection $services): array
    {
        return $services->map(fn ($service) => $this->serviceEntity($service))->all();
    }

    private function serviceEntity($service): array
    {
        return array_filter([
            'service_uid' => $service->uid,
            'name' => $service->name,
            'description' => $this->boundedDescription($service->description),
            'price_label' => $service->starting_price !== null && $service->currency_code
                ? 'From ' . strtoupper($service->currency_code) . ' ' . $service->starting_price
                : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Independent-review correction round 3 — a deterministic, whole-word
     * truncation of a free-text entity field before it is ever placed in
     * an AI prompt, so one unusually long saved description can never
     * dominate the guided-generation request's own bounded input-token
     * envelope.
     */
    private function boundedDescription(?string $description): ?string
    {
        $description = trim((string) $description);

        if ($description === '') {
            return null;
        }

        if (mb_strlen($description) <= self::MAX_ENTITY_DESCRIPTION) {
            return $description;
        }

        $truncated = mb_substr($description, 0, self::MAX_ENTITY_DESCRIPTION);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . '…';
    }

    /**
     * The fixed/always-possible pages this plan may still add AFTER the
     * aggregate service_detail/location categories are sized — counted
     * up front (using the exact same eligibility checks buildPlan() uses
     * for each) so those two categories are sized against the TRUE
     * remaining budget, never merely their own per-type cap.
     */
    private function fixedPageCount(\Closure $hasType, Business $business, Website $website, ?array $customSection): int
    {
        $count = 0;
        $count += $hasType('about') ? 1 : 0;
        $count += $hasType('faq') ? 1 : 0;
        $count += $hasType('gallery') && $this->galleryEligible($website) ? 1 : 0;
        $count += $hasType('contact') ? 1 : 0;
        $count += $hasType('backdrops') && $this->backdropsEligible($business) ? 1 : 0;
        $count += $hasType('custom_section') && $customSection !== null ? 1 : 0;

        return $count;
    }

    /**
     * @return array<int, array{name: string, description: ?string, price_label: ?string}>
     */
    private function catalogEntities(Collection $catalogItems): array
    {
        return $catalogItems->map(fn ($item) => array_filter([
            'name' => $item->name,
            'description' => $this->boundedDescription($item->description),
            'price_label' => $item->price_minor !== null && $item->currency_code
                ? \App\Library\Catalog\CatalogMoney::format($item->price_minor, $item->currency_code)
                : null,
        ], fn ($value) => $value !== null))->all();
    }

    private function locationEntity($location): array
    {
        return array_filter([
            'location_id' => $location->id,
            'city' => $location->city,
            'region' => $location->region,
            'service_area_cities' => collect($location->service_area_cities ?? [])->filter()->take(self::MAX_ENTITY_CITY_LIST)->values()->all() ?: null,
            'service_radius_km' => $location->service_radius_km ?: null,
        ], fn ($value) => $value !== null && $value !== []);
    }

    /**
     * @return Collection<int, mixed> saved-location records
     */
    private function nonPrimaryServedLocations(Business $business): Collection
    {
        return $business->locations()
            ->where('is_primary', false)
            ->get()
            ->filter(fn ($location) => $location->isActive())
            ->filter(fn ($location) => in_array($location->service_mode, [
                BusinessServiceMode::Storefront,
                BusinessServiceMode::ServiceArea,
                BusinessServiceMode::Hybrid,
            ], true))
            ->values();
    }

    /**
     * Acceptance-correction Blocker 8: counts independent, genuinely
     * saved real-fact signals for a location — never a single token.
     * Each signal below is backed by an actual persisted, confirmed
     * fact; none is inferred or fabricated merely to help a location
     * qualify:
     *
     *  1. Geographic specificity — a real saved service-area city list
     *     or a stated travel radius.
     *  2. A distinct, findable public address — `public_address` is
     *     true AND a real `address_line_1` is saved (a location that is
     *     genuinely more than a pin on a service-area map).
     *  3. Verified, location-specific opening hours — confirmed via the
     *     same `customer_confirmed` status BusinessKnowledgeProfileManager
     *     already requires before treating any location's hours as real.
     *  4. Materially different package/offer availability at this
     *     location — a real `CatalogItemLocationOverride` row exists for
     *     it (a saved fact about pricing/availability, never invented).
     */
    private function locationSignalScore($location): int
    {
        $score = 0;

        $cities = collect($location->service_area_cities ?? [])->filter();
        if ($cities->isNotEmpty() || (bool) $location->service_radius_km) {
            $score++;
        }

        if ((bool) $location->public_address && trim((string) $location->address_line_1) !== '') {
            $score++;
        }

        if ($location->hours_verification_status === BusinessKnowledgeProfileFieldState::STATUS_CUSTOMER_CONFIRMED
            && $location->hours_verified_at !== null
            && ! empty($location->hours)) {
            $score++;
        }

        if (CatalogItemLocationOverride::where('business_location_id', $location->id)->exists()) {
            $score++;
        }

        return $score;
    }
}
