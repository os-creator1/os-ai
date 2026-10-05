<?php

namespace Tests\Support\WebsiteAcceptance;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Website V1 full-site acceptance — one fetched HTML page, parsed once into
 * the facts the audit asks about (head metadata, headings, links, images,
 * structured data, section bands). Pure parsing: no application code, so
 * the audit judges the page exactly as a crawler would see it.
 */
final class PageDoc
{
    public readonly DOMDocument $dom;

    private readonly DOMXPath $xp;

    /** @param  array<string, string>  $headers lower-cased header name => value */
    public function __construct(
        public readonly string $url,
        public readonly int $status,
        public readonly string $html,
        public readonly array $headers = [],
    ) {
        $this->dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $this->dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $this->xp = new DOMXPath($this->dom);
    }

    /** @return array<int, string> */
    public function titles(): array
    {
        return $this->texts('//head/title');
    }

    /** @return array<int, string> every `<meta name=...>` content, in order */
    public function metaNamed(string $name): array
    {
        return $this->attrs('//head/meta[@name="' . $name . '"]', 'content');
    }

    /** @return array<int, string> */
    public function metaProperty(string $property): array
    {
        return $this->attrs('//head/meta[@property="' . $property . '"]', 'content');
    }

    /** @return array<int, string> */
    public function canonicals(): array
    {
        return $this->attrs('//head/link[@rel="canonical"]', 'href');
    }

    public function robotsMeta(): ?string
    {
        return $this->metaNamed('robots')[0] ?? null;
    }

    public function lang(): ?string
    {
        $html = $this->xp->query('//html')->item(0);

        return $html instanceof DOMElement && $html->getAttribute('lang') !== '' ? $html->getAttribute('lang') : null;
    }

    public function hasViewport(): bool
    {
        return $this->metaNamed('viewport') !== [];
    }

    /** @return array<int, array{level: int, text: string}> in document order, `main` only */
    public function headings(): array
    {
        $out = [];

        foreach ($this->xp->query('//main//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $node) {
            $out[] = ['level' => (int) substr($node->nodeName, 1), 'text' => self::clean($node->textContent)];
        }

        return $out;
    }

    /** @return array<int, string> */
    public function h1s(): array
    {
        return array_values(array_map(fn ($h) => $h['text'], array_filter($this->headingsAnywhere(), fn ($h) => $h['level'] === 1)));
    }

    /** @return array<int, array{level: int, text: string}> every heading in the whole body (the H1 may sit in the hero band) */
    public function headingsAnywhere(): array
    {
        $out = [];

        foreach ($this->xp->query('//body//*[self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::h6]') as $node) {
            $out[] = ['level' => (int) substr($node->nodeName, 1), 'text' => self::clean($node->textContent)];
        }

        return $out;
    }

    /** @return array<int, string> duplicated id attributes */
    public function duplicateIds(): array
    {
        $seen = [];
        $dupes = [];

        foreach ($this->xp->query('//*[@id]') as $node) {
            $id = $node->getAttribute('id');
            isset($seen[$id]) ? $dupes[$id] = $id : $seen[$id] = true;
        }

        return array_values($dupes);
    }

    /** @return array<int, string> every id (anchor targets) */
    public function ids(): array
    {
        $ids = [];
        foreach ($this->xp->query('//*[@id]') as $node) {
            $ids[] = $node->getAttribute('id');
        }

        return $ids;
    }

    /**
     * @return array<int, array{href: string, text: string, region: string, testid: string}>
     */
    public function links(): array
    {
        $out = [];

        foreach ($this->xp->query('//a[@href]') as $node) {
            /** @var DOMElement $node */
            $out[] = [
                'href' => trim($node->getAttribute('href')),
                'text' => self::clean($node->textContent),
                'region' => $this->regionOf($node),
                'testid' => $node->getAttribute('data-testid'),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{src: string, srcset: ?string, sizes: ?string, width: ?string, height: ?string, alt: ?string, loading: ?string, fetchpriority: ?string, class: string, region: string, in_hero: bool}>
     */
    public function images(): array
    {
        $out = [];

        foreach ($this->xp->query('//img') as $node) {
            /** @var DOMElement $node */
            $attr = fn (string $name) => $node->hasAttribute($name) ? $node->getAttribute($name) : null;
            $out[] = [
                'src' => (string) $attr('src'),
                'srcset' => $attr('srcset'),
                'sizes' => $attr('sizes'),
                'width' => $attr('width'),
                'height' => $attr('height'),
                'alt' => $attr('alt'),
                'loading' => $attr('loading'),
                'fetchpriority' => $attr('fetchpriority'),
                'class' => (string) $attr('class'),
                'region' => $this->regionOf($node),
                'in_hero' => $this->hasAncestorAttr($node, 'data-testid', 'site-hero'),
            ];
        }

        return $out;
    }

    /** @return array<int, string> raw JSON-LD script bodies */
    public function jsonLd(): array
    {
        return $this->texts('//script[@type="application/ld+json"]', false);
    }

    /** @return array<int, array{type: string, text: string, links: int, images: int}> the section bands in `main`, in order */
    public function sections(): array
    {
        $out = [];

        foreach ($this->xp->query('//main//*[@data-section]') as $node) {
            /** @var DOMElement $node */
            $out[] = [
                'type' => $node->getAttribute('data-section'),
                'text' => self::clean($node->textContent),
                'links' => $this->xp->query('.//a[@href]', $node)->length,
                'images' => $this->xp->query('.//img', $node)->length,
            ];
        }

        return $out;
    }

    /** @return array<int, string> form `action` URLs */
    public function formActions(): array
    {
        return $this->attrs('//form', 'action');
    }

    /**
     * Every visible FAQ item (question, answer) on the page, in order, as the page renders them.
     *
     * @return array<int, array{question: string, answer: string}>
     */
    public function faqItems(): array
    {
        $out = [];

        foreach ($this->xp->query('//main//details[contains(@class, "website-faq-item")]') as $node) {
            $question = $this->xp->query('./summary', $node)->item(0);
            $answer = $this->xp->query('./p', $node)->item(0);
            $out[] = ['question' => self::clean($question?->textContent ?? ''), 'answer' => self::clean($answer?->textContent ?? '')];
        }

        return $out;
    }

    /**
     * The (sorted) button hrefs of each call-to-action band in `main`.
     *
     * @return array<int, array<int, string>>
     */
    public function ctaBandHrefs(): array
    {
        $bands = [];

        foreach ($this->xp->query('//main//*[@data-section="cta"]') as $node) {
            $hrefs = [];
            foreach ($this->xp->query('.//a[@href]', $node) as $a) {
                /** @var DOMElement $a */
                $hrefs[] = $a->getAttribute('href');
            }
            sort($hrefs);
            $bands[] = $hrefs;
        }

        return $bands;
    }

    /** Visible text of `main` with whitespace collapsed (the content fingerprint). */
    public function mainText(): string
    {
        $main = $this->xp->query('//main')->item(0);

        return $main === null ? '' : self::clean($main->textContent);
    }

    /**
     * The visible text of `main`, band by band, leaving out the named section types. Preview
     * renders the quote form inert on purpose (labels only, a note, no submit), so parity
     * between Preview and the published site is judged on everything EXCEPT that one control.
     *
     * @param  array<int, string>  $exclude
     */
    public function mainTextExcluding(array $exclude): string
    {
        $parts = [];

        foreach ($this->xp->query('//main//*[@data-section]') as $node) {
            /** @var DOMElement $node */
            if (! in_array($node->getAttribute('data-section'), $exclude, true)) {
                $parts[] = self::clean($node->textContent);
            }
        }

        return implode(' | ', $parts);
    }

    /** Visible text of the whole body (header and footer included). */
    public function bodyText(): string
    {
        $body = $this->xp->query('//body')->item(0);

        return $body === null ? '' : self::clean($body->textContent);
    }

    public function bytes(): int
    {
        return strlen($this->html);
    }

    public function inlineStyleBytes(): int
    {
        $total = 0;
        foreach ($this->xp->query('//style') as $node) {
            $total += strlen($node->textContent);
        }

        return $total;
    }

    /** @return array<int, string> */
    public function dataUriLengths(): array
    {
        preg_match_all('/data:[a-z\/+\-]+;base64,[A-Za-z0-9+\/=]{2000,}/', $this->html, $matches);

        return $matches[0];
    }

    public static function clean(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** @return array<int, string> */
    private function texts(string $query, bool $clean = true): array
    {
        $out = [];
        foreach ($this->xp->query($query) as $node) {
            $out[] = $clean ? self::clean($node->textContent) : trim($node->textContent);
        }

        return $out;
    }

    /** @return array<int, string> */
    private function attrs(string $query, string $attribute): array
    {
        $out = [];
        foreach ($this->xp->query($query) as $node) {
            /** @var DOMElement $node */
            $out[] = trim($node->getAttribute($attribute));
        }

        return $out;
    }

    private function regionOf(DOMNode $node): string
    {
        for ($current = $node->parentNode; $current !== null; $current = $current->parentNode) {
            if ($current instanceof DOMElement) {
                if (in_array($current->nodeName, ['header', 'footer', 'main', 'nav'], true)) {
                    // A nav inside the header/footer is reported as that region.
                    if ($current->nodeName === 'nav') {
                        continue;
                    }

                    return $current->nodeName;
                }
            }
        }

        return 'other';
    }

    private function hasAncestorAttr(DOMNode $node, string $attribute, string $value): bool
    {
        for ($current = $node->parentNode; $current !== null; $current = $current->parentNode) {
            if ($current instanceof DOMElement && $current->getAttribute($attribute) === $value) {
                return true;
            }
        }

        return false;
    }
}
