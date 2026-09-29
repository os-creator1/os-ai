<?php

namespace App\Library\Website\GuidedGeneration;

use App\Library\Website\WebsiteSectionValidator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Website Guided Generation contract §8.2/§8.5, completed by this lane,
 * strengthened by acceptance-correction Blocker 2. Validates an
 * AI-authored generation batch — text and structure only, never images
 * (§8.3: the AI is never given an asset UID and never emits one) —
 * against the DETERMINISTIC plan (WebsitePageStrategy::buildPlan())
 * before ANY page is created. Fails the WHOLE batch together; there is
 * no such thing as a partially-valid attempt reaching persistence
 * (§8.2).
 *
 * The core acceptance-correction invariant: WebsitePageStrategy chooses
 * page INSTANCES; AI writes bounded content for THOSE instances and may
 * not add, remove, or reorder them. This class proves that invariant
 * mechanically — every plan page_key must appear in the output exactly
 * once, and no output page_key may exist outside the plan — in addition
 * to reusing WebsiteSectionValidator exactly as it already exists, and
 * checking that no generated text contains a `prohibited_claims`
 * phrase, and that every internal CTA/hero link target is a real
 * planned page slug (or tel:/mailto:) — never a broken or invented
 * internal link.
 */
final class GuidedGenerationOutputValidator
{
    public function __construct(
        private readonly WebsiteSectionValidator $sectionValidator,
    ) {
    }

    /**
     * @param  array<int, array{page_key: string, title: string, seo_title: ?string, meta_description: ?string, sections: array}>  $pages
     * @param  array  $plan  WebsitePageStrategy::buildPlan()'s output — the authoritative required page set
     * @param  array<int, string>  $prohibitedClaims
     * @throws ValidationException
     */
    public function validate(array $pages, array $plan, array $prohibitedClaims = []): void
    {
        if ($plan === []) {
            throw ValidationException::withMessages(['plan' => ['The generation plan contains no pages.']]);
        }

        $planByKey = collect($plan)->keyBy('page_key');
        $errors = [];

        $this->assertExactPlanCoverage($pages, $planByKey, $errors);

        // Every planned slug (home resolves to '/') is a valid internal
        // link target, regardless of whether AI happened to emit content
        // for it correctly above — a CTA is still allowed to point at
        // any real planned page.
        $knownSlugs = collect($plan)->map(fn ($page) => $page['is_home'] ? '' : (string) $page['slug'])->filter(fn ($slug) => $slug !== '')->values()->all();

        foreach ($pages as $index => $page) {
            $pageKey = $page['page_key'] ?? null;
            $planPage = $pageKey !== null ? $planByKey->get($pageKey) : null;

            if ($planPage === null) {
                // Already recorded by assertExactPlanCoverage() above.
                continue;
            }

            $allowedTypes = $planPage['allowed_section_types'] ?? [];
            foreach ($page['sections'] ?? [] as $sectionIndex => $section) {
                $type = $section['type'] ?? null;
                if (! in_array($type, $allowedTypes, true)) {
                    $errors["pages.{$index}.sections.{$sectionIndex}.type"][] = "Section type '{$type}' is not allowed on the '{$pageKey}' page.";
                }
            }

            try {
                // Never allows asset references — §8.3: the AI text
                // batch never carries an image field at all.
                $this->sectionValidator->validate($page['sections'] ?? [], [], allowAssetReferences: false);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $field => $messages) {
                    $errors["pages.{$index}.{$field}"] = $messages;
                }
            }

            $this->assertSeoFieldsWithinBounds($page, $index, $errors);
            $this->assertNoProhibitedClaims($page, $prohibitedClaims, $index, $errors);
            $this->assertInternalLinksResolve($page, $knownSlugs, $index, $errors);
        }

        $this->assertNoDuplicateSeoFields($pages, $errors);

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Acceptance-correction Blocker 2's core mechanical proof: exactly
     * one output page per plan page_key — no missing required page, no
     * extra invented page. AI never decides which pages exist.
     */
    private function assertExactPlanCoverage(array $pages, Collection $planByKey, array &$errors): void
    {
        $seenKeys = [];

        foreach ($pages as $index => $page) {
            $pageKey = $page['page_key'] ?? null;

            if ($pageKey === null || ! $planByKey->has($pageKey)) {
                $errors["pages.{$index}.page_key"][] = "'{$pageKey}' is not a page this generation plan calls for.";

                continue;
            }

            if (in_array($pageKey, $seenKeys, true)) {
                $errors["pages.{$index}.page_key"][] = "'{$pageKey}' is duplicated in the output — the plan calls for exactly one page per key.";

                continue;
            }

            $seenKeys[] = $pageKey;
        }

        foreach ($planByKey->keys() as $requiredKey) {
            if (! in_array($requiredKey, $seenKeys, true)) {
                $errors['plan.missing'][] = "Required planned page '{$requiredKey}' is missing from the generated output.";
            }
        }
    }

    /**
     * Same bounds WebsiteDraftPageService itself enforces
     * (seo_title max:70, meta_description max:160) — checked here too so
     * a batch that would fail at persistence time fails at validation
     * time instead, before any page is created (§8.2 whole-batch
     * atomicity).
     */
    private function assertSeoFieldsWithinBounds(array $page, int $index, array &$errors): void
    {
        foreach (['seo_title' => 70, 'meta_description' => 160] as $field => $maxLength) {
            $value = $page[$field] ?? null;

            if ($value === null) {
                continue;
            }

            if (! is_string($value)) {
                $errors["pages.{$index}.{$field}"][] = "'{$field}' must be a string or null.";

                continue;
            }

            if (mb_strlen($value) > $maxLength) {
                $errors["pages.{$index}.{$field}"][] = "'{$field}' must be {$maxLength} characters or fewer.";
            }
        }
    }

    /**
     * A generated seo_title/meta_description repeated across two pages
     * is exactly the boilerplate/duplicate-metadata pattern Search
     * Central's title-link guidance warns against — never allowed within
     * one generation batch.
     */
    private function assertNoDuplicateSeoFields(array $pages, array &$errors): void
    {
        foreach (['seo_title', 'meta_description'] as $field) {
            $seen = [];
            foreach ($pages as $index => $page) {
                $value = $page[$field] ?? null;
                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                if (in_array($value, $seen, true)) {
                    $errors["pages.{$index}.{$field}"][] = "'{$field}' duplicates another page in this batch.";
                }
                $seen[] = $value;
            }
        }
    }

    private function assertNoProhibitedClaims(array $page, array $prohibitedClaims, int $index, array &$errors): void
    {
        if ($prohibitedClaims === []) {
            return;
        }

        $haystack = mb_strtolower(json_encode($page));

        foreach ($prohibitedClaims as $claim) {
            $needle = mb_strtolower(trim((string) $claim));
            if ($needle !== '' && str_contains($haystack, $needle)) {
                $errors["pages.{$index}.prohibited_claims"][] = "Generated content contains a prohibited claim: '{$claim}'.";
            }
        }
    }

    /**
     * @param  array<int, string>  $knownSlugs  every non-home slug this generation plan calls for
     */
    private function assertInternalLinksResolve(array $page, array $knownSlugs, int $index, array &$errors): void
    {
        foreach ($page['sections'] ?? [] as $sectionIndex => $section) {
            $buttons = $section['data']['buttons'] ?? [];
            foreach ($buttons as $buttonIndex => $button) {
                $url = (string) ($button['url'] ?? '');

                if (! str_starts_with($url, '/')) {
                    continue;
                }

                $targetSlug = ltrim($url, '/');
                if ($targetSlug !== '' && ! in_array($targetSlug, $knownSlugs, true)) {
                    $errors["pages.{$index}.sections.{$sectionIndex}.buttons.{$buttonIndex}.url"][] = "Internal link '{$url}' does not resolve to any page in this generation plan.";
                }
            }
        }
    }
}
