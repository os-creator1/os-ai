<?php

namespace App\Library\Seo;

use App\Enums\Website\WebsiteSectionType;

/**
 * Contract 18 §9.1 — one page of the PUBLISHED Website snapshot, as SEO
 * sees it. Built from the immutable `website_revisions.snapshot` only; it
 * carries values, not a model, so nothing here can be saved back.
 */
final class SeoPublishedPage
{
    /**
     * @param  array<int, array<string, mixed>>  $sections  snapshot sections (`type`, `data`)
     */
    public function __construct(
        public readonly string $uid,
        public readonly ?string $slug,
        public readonly bool $isHome,
        public readonly string $title,
        public readonly ?string $seoTitle,
        public readonly ?string $metaDescription,
        public readonly bool $noindex,
        private readonly array $sections,
    ) {
    }

    public function hasSeoTitle(): bool
    {
        return $this->seoTitle !== null && trim($this->seoTitle) !== '';
    }

    public function hasMetaDescription(): bool
    {
        return $this->metaDescription !== null && trim($this->metaDescription) !== '';
    }

    /**
     * The page's text, split by where it appears, for keyword-coverage
     * matching (Contract 18 §8.4): the document title, the meta description,
     * and the body.
     *
     * The body is built from an ALLOWLIST of human-visible text fields per
     * section type — headings, body text, item names and descriptions, FAQ
     * answers and the visible labels of call-to-action buttons (page text a
     * searcher and a search engine both read) — never a URL, an asset uid, a
     * price label, a person's name or any other non-prose value, and an
     * unknown section type contributes nothing (fail closed).
     *
     * `contact_details` deliberately contributes nothing: its phone, email and
     * address are frozen at publish time but shown or withheld by a LIVE
     * privacy check on every request, so the snapshot cannot prove the text is
     * on the page now. A phrase found only there would be a false "covered".
     *
     * @return array{title: string, meta_description: string, body: string}
     */
    public function textSurfaces(): array
    {
        $body = [];

        foreach ($this->sections as $section) {
            $type = WebsiteSectionType::tryFrom((string) ($section['type'] ?? ''));
            $data = is_array($section['data'] ?? null) ? $section['data'] : [];

            foreach ($this->bodyTextsFor($type, $data) as $text) {
                $body[] = $text;
            }
        }

        return [
            'title' => trim(($this->hasSeoTitle() ? (string) $this->seoTitle : $this->title)),
            'meta_description' => trim((string) $this->metaDescription),
            'body' => implode("\n", $body),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function bodyTextsFor(?WebsiteSectionType $type, array $data): array
    {
        $texts = match ($type) {
            WebsiteSectionType::Hero => [
                $data['heading'] ?? null,
                $data['subheading'] ?? null,
                is_array($data['primary_cta'] ?? null) ? ($data['primary_cta']['label'] ?? null) : null,
                is_array($data['secondary_cta'] ?? null) ? ($data['secondary_cta']['label'] ?? null) : null,
            ],
            // The wizard's editorial/story section: the same visible heading + body as Text.
            WebsiteSectionType::Text, WebsiteSectionType::ImageText, WebsiteSectionType::CustomSection => [$data['heading'] ?? null, $data['body'] ?? null],
            WebsiteSectionType::Cta => array_merge(
                [$data['heading'] ?? null, $data['body'] ?? null],
                $this->itemFields($data, ['label'], 'buttons'),
            ),
            WebsiteSectionType::Services => array_merge(
                [$data['heading'] ?? null],
                $this->itemFields($data, ['name', 'description']),
            ),
            WebsiteSectionType::Testimonials => array_merge(
                [$data['heading'] ?? null],
                $this->itemFields($data, ['quote']),
            ),
            WebsiteSectionType::Faq => array_merge(
                [$data['heading'] ?? null],
                $this->itemFields($data, ['question', 'answer']),
            ),
            // Built from the Business's own backdrop rows: a visible heading and, per backdrop, its name and description.
            WebsiteSectionType::Backdrops => array_merge(
                [$data['heading'] ?? null],
                $this->itemFields($data, ['name', 'description']),
            ),
            // Only the section heading is prose (the form's fields live in the snapshot's forms, not in the section).
            WebsiteSectionType::Form, WebsiteSectionType::Gallery => [$data['heading'] ?? null],
            default => [],
        };

        return array_values(array_filter(
            $texts,
            fn ($text) => is_string($text) && trim($text) !== '',
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $fields
     * @param  string  $list  the key holding the list of items ("items", or "buttons" for a call to action)
     * @return array<int, mixed>
     */
    private function itemFields(array $data, array $fields, string $list = 'items'): array
    {
        $values = [];

        foreach ((is_array($data[$list] ?? null) ? $data[$list] : []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            foreach ($fields as $field) {
                $values[] = $item[$field] ?? null;
            }
        }

        return $values;
    }
}
