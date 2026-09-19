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
     * section type — never a URL, a button label, an asset uid or any other
     * non-prose value — and an unknown section type contributes nothing
     * (fail closed).
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
            WebsiteSectionType::Hero => [$data['heading'] ?? null, $data['subheading'] ?? null],
            WebsiteSectionType::Text, WebsiteSectionType::ImageText => [$data['heading'] ?? null, $data['body'] ?? null],
            WebsiteSectionType::Cta => [$data['heading'] ?? null, $data['body'] ?? null],
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
     * @return array<int, mixed>
     */
    private function itemFields(array $data, array $fields): array
    {
        $values = [];

        foreach ((is_array($data['items'] ?? null) ? $data['items'] : []) as $item) {
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
