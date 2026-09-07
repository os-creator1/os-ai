<?php

namespace App\Library\Website;

use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator as ValidatorFacade;
use Illuminate\Validation\ValidationException;

/**
 * Website Generation + Hosting Slice A contract §17.1 — the SOLE
 * authorized application seam for changing title/slug/seo_title/
 * meta_description/noindex/sections on a Business-scoped draft
 * WebsitePage. No other code path (present or future — including a
 * future SEO module) is authorized to UPDATE website_pages for these
 * fields directly. The caller (WebsiteController) is responsible for
 * the Workspace/Business/entitlement chain (§2.2/§26.2) before ever
 * reaching this service; this service completes the chain at the page
 * level (a page must belong to the given, already-resolved Website) and
 * owns every remaining invariant: slug rules, the homepage invariant,
 * component/field validation, and asset-reference validation.
 */
final class WebsiteDraftPageService
{
    public function __construct(
        private readonly WebsiteSectionValidator $sectionValidator,
    ) {
    }

    /**
     * @throws ValidationException
     */
    public function createPage(Website $website, array $attributes): WebsitePage
    {
        $validated = $this->validateAttributes($website, $attributes, null);

        return DB::transaction(function () use ($website, $validated) {
            if ($validated['is_home']) {
                $this->clearExistingHomepage($website);
            }

            return $website->pages()->create($validated);
        });
    }

    /**
     * @throws ValidationException
     */
    public function updatePage(Website $website, WebsitePage $page, array $attributes): WebsitePage
    {
        abort_unless($page->website_id === $website->id, 404);

        $validated = $this->validateAttributes($website, $attributes, $page);

        return DB::transaction(function () use ($website, $page, $validated) {
            if ($validated['is_home'] && ! $page->is_home) {
                $this->clearExistingHomepage($website);
            }

            $page->update($validated);

            return $page->refresh();
        });
    }

    /**
     * Contract §6.2/§34: a Website is never left with zero homepages and
     * never left with zero pages. Deleting the current homepage requires
     * $promoteUid naming another existing page to take over as home, in
     * the same transaction.
     */
    public function deletePage(Website $website, WebsitePage $page, ?string $promoteUid = null): void
    {
        abort_unless($page->website_id === $website->id, 404);

        if ($website->pages()->count() <= 1) {
            throw ValidationException::withMessages([
                'page' => ['A Website must always retain at least one page.'],
            ]);
        }

        if ($page->is_home) {
            if ($promoteUid === null) {
                throw ValidationException::withMessages([
                    'page' => ['Promote another page to homepage before deleting the current one.'],
                ]);
            }

            $replacement = $website->pages()->where('uid', $promoteUid)->where('id', '!=', $page->id)->first();

            if ($replacement === null) {
                throw ValidationException::withMessages([
                    'page' => ['The page to promote as the new homepage was not found.'],
                ]);
            }

            DB::transaction(function () use ($replacement, $page) {
                $replacement->lockForUpdate();
                $replacement->update(['is_home' => true, 'slug' => null]);
                $page->delete();
            });

            return;
        }

        $page->delete();
    }

    /**
     * @throws ValidationException
     */
    private function validateAttributes(Website $website, array $attributes, ?WebsitePage $existing): array
    {
        $isHome = (bool) ($attributes['is_home'] ?? $existing?->is_home ?? false);

        $rules = [
            'title' => 'required|string|max:150',
            'seo_title' => 'nullable|string|max:70',
            'meta_description' => 'nullable|string|max:160',
            'noindex' => 'nullable|boolean',
            'sections' => 'nullable|array',
        ];

        $validator = ValidatorFacade::make($attributes, $rules);
        $validator->validate();

        $slug = $attributes['slug'] ?? null;

        if (! $isHome) {
            if (empty($slug) || ! WebsiteSlugRules::isValid((string) $slug)) {
                throw ValidationException::withMessages([
                    'slug' => ['A valid slug is required for a non-homepage page.'],
                ]);
            }

            $conflict = $website->pages()
                ->where('slug', $slug)
                ->when($existing !== null, fn ($query) => $query->where('id', '!=', $existing->id))
                ->exists();

            if ($conflict) {
                throw ValidationException::withMessages([
                    'slug' => ['This slug is already used by another page on this Website.'],
                ]);
            }
        } else {
            $slug = null;
        }

        $sections = $attributes['sections'] ?? ($existing->sections ?? []);
        $validAssetUids = WebsiteAsset::where('website_id', $website->id)->pluck('uid')->all();

        $this->sectionValidator->validate($sections, $validAssetUids, true);

        return [
            'title' => $attributes['title'],
            'slug' => $slug,
            'is_home' => $isHome,
            'sections' => $sections,
            'seo_title' => $attributes['seo_title'] ?? null,
            'meta_description' => $attributes['meta_description'] ?? null,
            'noindex' => (bool) ($attributes['noindex'] ?? false),
        ];
    }

    private function clearExistingHomepage(Website $website): void
    {
        $current = $website->pages()->where('is_home', true)->lockForUpdate()->first();

        if ($current !== null) {
            $current->update(['is_home' => false]);
        }
    }
}
