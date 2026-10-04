<?php

namespace App\Library\Website\GuidedGeneration;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\BusinessBackdrop;
use App\Models\CatalogItem;
use App\Models\CatalogItemImage;
use App\Models\Website;
use App\Models\WebsiteAsset;

/**
 * Website Guided Generation contract §8.7, completed by this lane,
 * strengthened by acceptance-correction Blocker 9 and independent-review
 * correction round 4. The ONE place that ever assigns an image OR a form
 * to a generated page — the AI text batch never carries an image field
 * (§8.3) or a form_uid, and this service never invents either: an image
 * can only ever come from a `WebsiteAsset` row that already belongs to
 * this exact Website, and a form can only ever be the Website's own real
 * `WebsiteForm` row (never a cross-Website reference, never AI-invented).
 * A slot with no eligible asset is left empty and recorded in `warnings`
 * (task instruction: "expose a clear missing-media checklist"), never
 * silently rendered broken and never filled with a placeholder.
 *
 * Bounded purposes, in deterministic upload-order:
 *  - home hero (the first uploaded asset only — never reused as any
 *    other page's hero, so the same photo does not repeat as the
 *    hero on every page);
 *  - each `image_text` section on any OTHER page (service_detail pages,
 *    in the current template manifests) — AI is free to write an
 *    `image_text` section's heading/body/image_position, but never its
 *    `image` (GuidedGenerationOutputValidator validates that section
 *    with WebsiteSectionValidator's `requireImageOnImageText: false`,
 *    acceptance-correction round 2, Blocker 1); this service fills a
 *    real, distinct, round-robined asset into that empty slot — and if
 *    genuinely no asset exists at all, drops the section entirely
 *    (stripUnfillableImageTextSections()) rather than ever persisting
 *    an `image_text` section with no image, which would fail
 *    WebsiteDraftPageService's own strict re-validation at commit time;
 *  - the Gallery page's own `gallery` section — built ENTIRELY here
 *    from every real uploaded asset, never by AI: the section validator
 *    categorically rejects any `image` value in AI-authored content
 *    (§8.3), so a 'gallery' section is never something the guided AI
 *    client asks for or the output validator accepts — this service is
 *    the only source of that section's content;
 *  - the Contact page's own `form` section — built ENTIRELY here
 *    (bindForms()) from the Website's real quote-request form, reusing
 *    WebsiteStarterDraftService::ensurePhotoBoothQuoteForm() exactly as
 *    the deterministic starter draft does, so a generated or rebuilt
 *    Contact page always retains a real, submittable, Website-owned
 *    form — 'form' is never something the guided AI client asks for or
 *    the output validator accepts (WebsitePageStrategy::
 *    withoutAiUnfillableSections()).
 *
 * `about`/team photography is deliberately NOT a supported purpose yet:
 * the `about` page_type's own manifest does not allow any image-bearing
 * section type today, so there is no bounded slot to attach one to
 * without inventing a new section primitive — out of this correction's
 * scope (see docs/automation/WEBSITE-GENERATOR-SEO-COMPLETION-NOTE.md).
 */
final class MediaBindingService
{
    public function __construct(private readonly \App\Library\Website\WebsiteCatalogReferences $catalogReferences)
    {
    }

    /**
     * Guarantees every service-area page real internal links, whatever the
     * AI wrote: one call-to-action to the contact page and the services
     * overview (when they exist) and one pointing at up to two of the
     * owner's other area pages. Deterministic — built only from the
     * planned slugs, never from AI output.
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, array<string, mixed>>
     */
    private function linkAreaPages(array $pages): array
    {
        $slugOf = fn (string $type) => collect($pages)->firstWhere('page_type', $type)['slug'] ?? null;
        $contactSlug = $slugOf('contact');
        $servicesSlug = $slugOf('services_overview');
        $areaPages = array_values(array_filter($pages, fn ($p) => is_array($p['entity'] ?? null) && isset($p['entity']['area'])));

        if ($areaPages === []) {
            return $pages;
        }

        $linksTo = function (array $page, string $slug): bool {
            foreach ($page['sections'] ?? [] as $section) {
                foreach ($section['data']['buttons'] ?? [] as $button) {
                    if (ltrim((string) ($button['url'] ?? ''), '/') === $slug) {
                        return true;
                    }
                }
            }

            return false;
        };

        foreach ($pages as $index => $page) {
            $area = is_array($page['entity'] ?? null) ? ($page['entity']['area'] ?? null) : null;

            if (! is_string($area)) {
                continue;
            }

            $buttons = [];
            if ($contactSlug && ! $linksTo($page, $contactSlug)) {
                $buttons[] = ['label' => 'Get in touch', 'url' => '/' . $contactSlug];
            }
            if ($servicesSlug && ! $linksTo($page, $servicesSlug)) {
                $buttons[] = ['label' => 'See our services', 'url' => '/' . $servicesSlug];
            }
            if ($buttons !== []) {
                $pages[$index]['sections'][] = ['type' => 'cta', 'data' => [
                    'heading' => \Illuminate\Support\Str::limit('Planning an event in ' . $area . '?', 120, ''),
                    'body' => null,
                    'buttons' => array_slice($buttons, 0, 2),
                ]];
            }

            $nearby = [];
            foreach ($areaPages as $other) {
                if ($other['slug'] !== $page['slug'] && ! $linksTo($page, (string) $other['slug'])) {
                    $nearby[] = ['label' => \Illuminate\Support\Str::limit('Also serving ' . $other['entity']['area'], 40, ''), 'url' => '/' . $other['slug']];
                }
                if (count($nearby) === 2) {
                    break;
                }
            }
            if ($nearby !== []) {
                $pages[$index]['sections'][] = ['type' => 'cta', 'data' => [
                    'heading' => 'We also serve nearby',
                    'body' => null,
                    'buttons' => $nearby,
                ]];
            }
        }

        return $pages;
    }

    /**
     * @param  array<int, array{page_key: string, page_type: string, is_home: bool, slug: ?string, title: string, seo_title: ?string, meta_description: ?string, sections: array}>  $pages
     * @param  ?array{title: string, layout: string, body: ?string, images: array<int, string>}  $customSection  see WebsitePageStrategy::buildPlan()'s matching parameter
     * @param  ?array<int, array{question: string, answer: string}>  $customerFaq  the owner's own FAQ answers (WebsiteSetupAnswerApplier never writes these anywhere canonical — 'faq' has no canonical model — so the wizard controller reads them straight from QuestionnaireResponse.answers and hands them here)
     * @return array{pages: array, warnings: array<int, string>}
     */
    public function bind(Website $website, array $pages, ?array $customSection = null, ?array $customerFaq = null): array
    {
        $pages = $this->bindForms($website, $pages);
        $pages = $this->bindBackdrops($website, $pages);
        $pages = $this->bindCustomSection($pages, $customSection);
        $pages = $this->bindCustomerFaq($pages, $customerFaq);
        // Package blocks reference their canonical Packages & Products row
        // by uid (resolved live at preview/publish) — stamped here from the
        // catalog, never trusted from AI output.
        $pages = $this->linkAreaPages($pages);
        $pages = $this->catalogReferences->stampPackagePages($pages, (int) $website->business_id);
        $pages = $this->mirrorPackageImages($website, $pages);

        // Independent-review correction round 2 — only GALLERY-purpose
        // assets are ever eligible for the hero, image_text pool, or
        // Gallery page. A custom-section photo or a derived package-
        // mirror image must never enter this general pool (they exist
        // for one narrow, already-bound purpose each — see
        // bindCustomSection()/mirrorPackageImages()). Ordered by the
        // owner's own gallery `sort_order`, not insertion id.
        $assets = $website->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->orderBy('sort_order')->orderBy('id')->get();
        $warnings = [];

        if ($assets->isEmpty()) {
            $warnings[] = 'No uploaded photos are available yet — every page was generated without any photography.';
            $pages = $this->stripUnfillableImageTextSections($pages, $warnings);

            return ['pages' => $this->fillGalleryFromAssets($pages, $assets->all(), $warnings), 'warnings' => $warnings];
        }

        // Independent-review correction round 2 — the owner's selected
        // cover (WebsiteGalleryManager::setCover()) is the homepage hero
        // candidate whenever one is set; only when none exists does the
        // first asset by sort_order act as the deterministic fallback.
        $heroAsset = $assets->firstWhere('is_cover', true) ?? $assets->first();
        $heroAssetUid = $heroAsset->uid;
        $remainingPool = $assets->reject(fn (WebsiteAsset $asset) => $asset->is($heroAsset))->values();
        // Every asset is still eligible for the round-robin pool at
        // least once, even when only one photo exists in total — a
        // service page's own inline photo is not "the hero repeated on
        // every page" merely because it is the only photo available.
        $roundRobinPool = $remainingPool->isNotEmpty() ? $remainingPool : $assets;
        $cursor = 0;

        $boundHome = false;
        $imageTextSlotsSeen = 0;

        foreach ($pages as $index => $page) {
            foreach ($page['sections'] ?? [] as $sectionIndex => $section) {
                $type = $section['type'] ?? null;

                if ($type === 'hero' && ($page['page_type'] ?? null) === 'home' && ! $boundHome) {
                    $pages[$index]['sections'][$sectionIndex]['data']['background_image'] = $heroAssetUid;
                    $boundHome = true;

                    continue;
                }

                if ($type === 'image_text' && empty($section['data']['image'])) {
                    $imageTextSlotsSeen++;

                    // roundRobinPool is never empty once $assets itself
                    // is non-empty (see the fallback above), so every
                    // slot is always filled with SOMETHING real — the
                    // only open question is whether it had to repeat.
                    $asset = $roundRobinPool[$cursor % $roundRobinPool->count()];
                    $pages[$index]['sections'][$sectionIndex]['data']['image'] = $asset->uid;
                    $cursor++;
                }
            }
        }

        if (! $boundHome) {
            $warnings[] = 'The Home page has no hero section to attach a photo to.';
        }

        // A real, meaningful signal (some slot necessarily repeats a
        // photo already used elsewhere) rather than "a slot went
        // unfilled," which never happens once at least one asset exists.
        // Two ways this happens: only one asset exists in total (every
        // inline slot then necessarily repeats the hero's own photo), or
        // there are more inline slots than the non-hero pool can cover
        // distinctly.
        $onlyOneAssetTotal = $remainingPool->isEmpty();
        if ($imageTextSlotsSeen > 0 && ($onlyOneAssetTotal || $imageTextSlotsSeen > $roundRobinPool->count())) {
            $warnings[] = 'Not enough distinct uploaded photos for every photo slot — some photos repeat across pages.';
        }

        // Defense in depth: once at least one asset exists, the
        // round-robin fallback above always fills every image_text slot
        // with something real, so this is a no-op in the ordinary case —
        // it only matters if a future change to the pool logic above
        // ever leaves a slot genuinely unfillable.
        $pages = $this->stripUnfillableImageTextSections($pages, $warnings);

        return ['pages' => $this->fillGalleryFromAssets($pages, $assets->all(), $warnings), 'warnings' => $warnings];
    }

    /**
     * Acceptance-correction round 2, Blocker 1's "define safe behavior
     * when no usable photo exists": an `image_text` section whose
     * `image` is still empty after every binding attempt is dropped
     * from the page entirely, never persisted half-valid. Persisting it
     * with a null `image` would fail WebsiteDraftPageService::
     * createPage()'s own strict re-validation at commit time (§8.2's
     * defense-in-depth), which — inside the same atomic transaction as
     * every other page in the batch — would abort the whole commit
     * rather than safely omitting one section. Dropping it here instead
     * means the page still ships with all its OTHER real content; the
     * missing photo is only ever surfaced as a warning, never a crash
     * and never a broken rendered section.
     */
    private function stripUnfillableImageTextSections(array $pages, array &$warnings): array
    {
        $dropped = false;

        foreach ($pages as $index => $page) {
            $sections = array_values(array_filter($page['sections'] ?? [], function ($section) use (&$dropped) {
                if (($section['type'] ?? null) === 'image_text' && empty($section['data']['image'] ?? null)) {
                    $dropped = true;

                    return false;
                }

                return true;
            }));

            $pages[$index]['sections'] = $sections;
        }

        if ($dropped) {
            $warnings[] = 'An image_text section could not be given a real photo and was left out of its page.';
        }

        return $pages;
    }

    /**
     * The Contact page's `form` section is always constructed here from
     * the Website's real quote-request form, never merged with anything
     * AI wrote (AI is never asked for, and the output validator never
     * accepts, a 'form' section — see class docblock). Idempotent and
     * side-effect-free beyond that one form row: ensurePhotoBoothQuoteForm()
     * reuses an existing form rather than creating a duplicate, exactly
     * as the deterministic starter draft's own Contact page already
     * does, so a Contact page built either way ends up with the same
     * real, submittable form. Runs before the asset lookup above and
     * independently of photo availability — a Contact page's form is
     * never conditional on whether any photos have been uploaded yet.
     */
    private function bindForms(Website $website, array $pages): array
    {
        foreach ($pages as $index => $page) {
            if (($page['page_type'] ?? null) !== 'contact') {
                continue;
            }

            $hasForm = collect($page['sections'] ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'form');
            if ($hasForm) {
                continue;
            }

            $form = WebsiteStarterDraftService::ensurePhotoBoothQuoteForm($website);
            $pages[$index]['sections'][] = ['type' => 'form', 'data' => ['heading' => 'Request a quote', 'form_uid' => $form->uid]];
        }

        return $pages;
    }

    /**
     * The Backdrops page's `backdrops` section is always constructed here
     * from the Business's own real, available BusinessBackdrop rows —
     * never merged with anything AI wrote (see class docblock). Image
     * references are resolved URLs from BusinessBackdropImage::url()
     * (Business-owned, not Website-owned), so — unlike a `gallery` or
     * `image_text` slot — there is nothing here for
     * WebsiteSectionValidator's Website-scoped asset-uid check to
     * validate against, by design.
     */
    private function bindBackdrops(Website $website, array $pages): array
    {
        foreach ($pages as $index => $page) {
            if (($page['page_type'] ?? null) !== 'backdrops') {
                continue;
            }

            $hasSection = collect($page['sections'] ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'backdrops');
            if ($hasSection) {
                continue;
            }

            $backdrops = BusinessBackdrop::where('business_id', $website->business_id)
                ->where('availability', true)
                ->orderBy('position')
                ->with('images')
                ->get();

            $items = $backdrops
                ->map(fn (BusinessBackdrop $backdrop) => [
                    'name' => $backdrop->name,
                    'description' => $backdrop->description,
                    'availability' => true,
                    'images' => $backdrop->images->map(fn ($image) => [
                        'url' => $image->url(),
                        'alt_text' => $image->alt_text,
                    ])->all(),
                ])
                ->filter(fn (array $item) => $item['images'] !== [])
                ->values()
                ->all();

            if ($items === []) {
                continue;
            }

            $pages[$index]['sections'][] = ['type' => 'backdrops', 'data' => ['heading' => 'Our Backdrops', 'items' => $items]];
        }

        return $pages;
    }

    /**
     * The optional custom section's content is always the owner's own
     * questionnaire answer, applied here verbatim (whether the body text
     * was hand-typed or AI-drafted at answer time — either way it is
     * already a fixed string by the time generation runs) — never
     * something the main guided-generation AI call writes or rewrites.
     */
    private function bindCustomSection(array $pages, ?array $customSection): array
    {
        if ($customSection === null) {
            return $pages;
        }

        foreach ($pages as $index => $page) {
            if (($page['page_type'] ?? null) !== 'custom_section') {
                continue;
            }

            $hasSection = collect($page['sections'] ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'custom_section');
            if ($hasSection) {
                continue;
            }

            $pages[$index]['sections'][] = [
                'type' => 'custom_section',
                'data' => [
                    'heading' => $customSection['title'],
                    'body' => $customSection['body'] ?? '',
                    'layout' => $customSection['layout'] ?? 'stacked',
                    'images' => $customSection['images'] ?? [],
                ],
            ];
        }

        return $pages;
    }

    /**
     * Independent-review correction round 2 — the owner's own
     * customer-entered FAQ question/answer pairs are appended to the FAQ
     * page's `faq` section VERBATIM, never through AI (the task's own
     * instruction: "Do not ask AI to rewrite factual customer-entered
     * Q&A"). A section AI did write on the same page keeps its own
     * (general, non-customer-specific) entries; the customer's real
     * pairs are added alongside them, always present regardless of
     * whether AI wrote anything for this page at all.
     *
     * @param  ?array<int, array{question: string, answer: string}>  $customerFaq
     */
    private function bindCustomerFaq(array $pages, ?array $customerFaq): array
    {
        if ($customerFaq === null || $customerFaq === []) {
            return $pages;
        }

        foreach ($pages as $index => $page) {
            if (($page['page_type'] ?? null) !== 'faq') {
                continue;
            }

            $sectionIndex = collect($page['sections'] ?? [])->search(fn ($section) => ($section['type'] ?? null) === 'faq');

            if ($sectionIndex === false) {
                $pages[$index]['sections'][] = ['type' => 'faq', 'data' => ['heading' => 'Frequently Asked Questions', 'items' => []]];
                $sectionIndex = array_key_last($pages[$index]['sections']);
            }

            // Customer-entered pairs take priority over AI's generic
            // filler — WebsiteSectionValidator caps a faq section at 20
            // items total, so the real, factual customer pairs go first
            // and AI's own entries only fill whatever room remains.
            $existingItems = $pages[$index]['sections'][$sectionIndex]['data']['items'] ?? [];
            $pages[$index]['sections'][$sectionIndex]['data']['items'] = array_slice(
                array_merge($customerFaq, $existingItems),
                0,
                20,
            );
        }

        return $pages;
    }

    /**
     * The Packages page's `services` section lists real CatalogItem
     * facts (WebsitePageStrategy::catalogEntities()) but, like every
     * other AI-authored section, may never carry an image AI chose
     * itself. A package's own cover image (CatalogItemImage, Business-
     * owned) is mirrored into a WebsiteAsset row here — matched to its
     * item by name (the same canonical fact AI was given and instructed
     * to use verbatim) — purely so WebsiteSectionValidator has a real,
     * Website-scoped asset uid to validate against; this is a narrow,
     * idempotent derived copy of one image file for rendering, never a
     * duplicate of the package's own structured data (see
     * create_catalog_item_images_table migration and this class's own
     * architectural-decision note in the Website Builder redesign plan).
     * A package with no cover image, or no matching item, is left exactly
     * as AI wrote it — never a broken or fabricated slot.
     */
    private function mirrorPackageImages(Website $website, array $pages): array
    {
        $catalogItemsByName = CatalogItem::where('business_id', $website->business_id)
            ->where('lifecycle_state', CatalogItemLifecycleState::Active->value)
            ->with('images')
            ->get()
            ->keyBy(fn (CatalogItem $item) => mb_strtolower(trim($item->name)));

        if ($catalogItemsByName->isEmpty()) {
            return $pages;
        }

        foreach ($pages as $index => $page) {
            if (($page['page_type'] ?? null) !== 'packages') {
                continue;
            }

            foreach ($page['sections'] ?? [] as $sectionIndex => $section) {
                if (($section['type'] ?? null) !== 'services') {
                    continue;
                }

                foreach (($section['data']['items'] ?? []) as $itemIndex => $item) {
                    if (! empty($item['image'])) {
                        continue;
                    }

                    $catalogItem = $catalogItemsByName->get(mb_strtolower(trim($item['name'] ?? '')));
                    $cover = $catalogItem?->coverImage();

                    if ($cover === null) {
                        continue;
                    }

                    $mirrored = $this->mirroredAssetFor($website, $cover);
                    $pages[$index]['sections'][$sectionIndex]['data']['items'][$itemIndex]['image'] = $mirrored->uid;
                }
            }
        }

        return $pages;
    }

    /**
     * Idempotent by source: a repeat generation/rebuild finds the
     * already-mirrored asset by its provenance link rather than creating
     * a second copy every time.
     */
    private function mirroredAssetFor(Website $website, CatalogItemImage $cover): WebsiteAsset
    {
        $existing = WebsiteAsset::where('website_id', $website->id)
            ->where('source_catalog_item_image_id', $cover->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        return WebsiteAsset::create([
            'website_id' => $website->id,
            'disk' => $cover->disk,
            'path' => $cover->path,
            'mime_type' => $cover->mime_type,
            'size' => $cover->size,
            'width' => $cover->width,
            'height' => $cover->height,
            'alt_text' => $cover->alt_text,
            'source_catalog_item_image_id' => $cover->id,
            // Independent-review correction round 2 — a derived,
            // idempotent copy for section-validator purposes only; it
            // must never enter the general hero/gallery pool (see
            // bind()'s own purpose-scoped asset query) and never counts
            // against a customer's upload storage allowance.
            'purpose' => WebsiteAssetPurpose::PackageMirror->value,
        ]);
    }

    /**
     * The Gallery page's `gallery` section is always constructed here
     * from real assets, never merged with anything AI wrote (AI is
     * never asked for, and the output validator never accepts, a
     * 'gallery' section — see class docblock). A Gallery page with zero
     * usable assets gets no gallery section at all rather than an empty
     * or fabricated one; WebsitePageStrategy::galleryEligible() already
     * keeps this page out of the plan below the minimum-photo bar, so
     * this is only ever a defensive fallback (e.g. photos deleted
     * between plan-building and commit).
     *
     * @param  array<int, \App\Models\WebsiteAsset>  $assets
     */
    private function fillGalleryFromAssets(array $pages, array $assets, array &$warnings): array
    {
        foreach ($pages as $index => $page) {
            if (($page['page_type'] ?? null) !== 'gallery') {
                continue;
            }

            if ($assets === []) {
                $warnings[] = 'The Gallery page has no uploaded photos yet.';

                continue;
            }

            $items = array_map(fn ($asset) => ['image' => $asset->uid], array_slice($assets, 0, 24));
            $pages[$index]['sections'][] = ['type' => 'gallery', 'data' => ['heading' => 'Photos', 'items' => $items]];
        }

        return $pages;
    }
}
