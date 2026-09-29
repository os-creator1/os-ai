<?php

namespace App\Library\Website\GuidedGeneration;

use App\Library\Website\WebsiteSectionValidator;
use App\Models\WebsiteTemplate;
use Illuminate\Validation\ValidationException;

/**
 * Website Guided Generation contract §8.2/§8.5, completed by this lane.
 * Validates an AI-authored generation batch — text and structure only,
 * never images (§8.3: the AI is never given an asset UID and never
 * emits one) — before ANY page is created. Fails the WHOLE batch
 * together; there is no such thing as a partially-valid attempt
 * reaching persistence (§8.2).
 *
 * Reuses WebsiteSectionValidator exactly as it already exists — this
 * class adds three checks that validator has no reason to know about:
 * that every page/section type is actually inside the chosen
 * template's own manifest, that no generated text contains a
 * `prohibited_claims` phrase, and that every internal CTA/hero link
 * target is a real page in this same batch (or tel:/mailto:) — never a
 * broken or invented internal link.
 */
final class GuidedGenerationOutputValidator
{
    public function __construct(
        private readonly WebsiteSectionValidator $sectionValidator,
    ) {
    }

    /**
     * @param  array<int, array{page_type: string, title: string, slug: ?string, sections: array}>  $pages
     * @param  array<int, string>  $prohibitedClaims
     * @throws ValidationException
     */
    public function validate(array $pages, WebsiteTemplate $template, array $prohibitedClaims = []): void
    {
        if ($pages === []) {
            throw ValidationException::withMessages(['pages' => ['Generation produced no pages.']]);
        }

        $manifestByType = collect($template->page_manifest['pages'] ?? [])->keyBy('page_type');
        $errors = [];
        $knownSlugs = collect($pages)->pluck('slug')->filter()->all();

        foreach ($pages as $index => $page) {
            $pageType = $page['page_type'] ?? null;
            $manifestPage = $pageType !== null ? $manifestByType->get($pageType) : null;

            if ($manifestPage === null) {
                $errors["pages.{$index}.page_type"][] = "'{$pageType}' is not a page type this template supports.";

                continue;
            }

            $allowedTypes = $manifestPage['allowed_section_types'] ?? [];
            foreach ($page['sections'] ?? [] as $sectionIndex => $section) {
                $type = $section['type'] ?? null;
                if (! in_array($type, $allowedTypes, true)) {
                    $errors["pages.{$index}.sections.{$sectionIndex}.type"][] = "Section type '{$type}' is not allowed on a '{$pageType}' page.";
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
     * @param  array<int, string>  $knownSlugs  every slug this SAME generation batch will create
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
                if (! in_array($targetSlug, $knownSlugs, true) && $targetSlug !== '') {
                    $errors["pages.{$index}.sections.{$sectionIndex}.buttons.{$buttonIndex}.url"][] = "Internal link '{$url}' does not resolve to any page in this generation batch.";
                }
            }
        }
    }
}
