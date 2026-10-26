<?php

namespace App\Library\Legal;

use Illuminate\Support\Str;

/**
 * Reads and renders the nine legal documents. The single text source is resources/legal/<slug>.md; the
 * registry (titles, versions, SEO) is config/legal.php; the operator's identity is LegalCompany.
 *
 * Rendering is Markdown with raw HTML stripped and unsafe links refused, then company placeholders
 * ({{legal_name}} and friends) are filled with HTML-escaped values AFTER the Markdown pass, so an address
 * can never be interpreted as markup or formatting.
 */
class LegalDocuments
{
    public function __construct(private readonly LegalCompany $company)
    {
    }

    /** @return array<string, array<string, mixed>> slug => registry entry (with `slug` and `url`) */
    public function all(): array
    {
        $out = [];
        foreach ((array) config('legal.documents') as $slug => $meta) {
            $out[$slug] = $meta + ['slug' => $slug, 'url' => url($slug)];
        }

        return $out;
    }

    public function tier(string $tier): array
    {
        return array_filter($this->all(), fn ($d) => $d['tier'] === $tier);
    }

    public function meta(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * @param  array<string, mixed>|null  $company  A company revision to render with; null = the current one.
     * @return array{meta: array, html: string, toc: list<array{id: string, label: string}>, hash: string, company: array}
     */
    public function render(string $slug, ?array $company = null): array
    {
        $meta = $this->meta($slug) ?? throw new \InvalidArgumentException('Unknown legal document: '.$slug);
        $company ??= $this->company->current();

        $markdown = (string) file_get_contents(resource_path('legal/'.$slug.'.md'));
        $html = Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        $toc = [];
        $used = [];
        $html = preg_replace_callback('#<h2>(.*?)</h2>#s', function ($m) use (&$toc, &$used) {
            $label = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $id = Str::slug($label) ?: 'section';
            for ($n = 2; isset($used[$id]); $n++) {
                $id = Str::slug($label).'-'.$n;
            }
            $used[$id] = true;
            $toc[] = ['id' => $id, 'label' => $label];

            return '<h2 id="'.$id.'" class="lg-h2">'.$m[1].'</h2>';
        }, $html);

        $html = str_replace(['<table>', '</table>'], ['<div class="lg-table"><table>', '</table></div>'], $html);
        $html = strtr($html, $this->placeholders($company));

        return ['meta' => $meta, 'html' => $html, 'toc' => $toc, 'hash' => hash('sha256', $html), 'company' => $company];
    }

    /** @return array<string, string> placeholder => escaped (HTML-safe) replacement */
    private function placeholders(array $company): array
    {
        $line = fn (string $v) => e(trim((string) preg_replace('/\s*[\r\n]+\s*/', ', ', $v)));
        $email = e($company['legal_email']);

        return [
            '{{brand_name}}' => $line($company['brand_name']),
            '{{legal_name}}' => $line($company['legal_entity']),
            '{{company_number}}' => $line($company['company_number']),
            '{{registered_address}}' => $line($company['registered_address']),
            '{{country}}' => $line($company['country']),
            '{{legal_email}}' => '<a href="mailto:'.$email.'">'.$email.'</a>',
        ];
    }
}
