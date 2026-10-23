<?php

namespace App\Library\Legal;

use Illuminate\Support\Str;

/**
 * Renders a legal document's markdown source to safe HTML and extracts its
 * section list for the contents navigation.
 *
 * Safety: raw HTML in the source is stripped and unsafe link schemes are
 * refused by CommonMark; company-fact values are escaped (HTML and markdown)
 * before they are substituted.
 */
final class LegalRenderer
{
    /**
     * @param  array<string, array{label: string, value: ?string}>  $facts
     * @return array{html: string, sections: list<array{id: string, number: ?string, title: string}>, unresolved: list<string>}
     */
    public function render(string $source, array $facts): array
    {
        $text = $this->substitute(LegalDocument::canonical($source), $facts);

        $sections = [];
        $used = [];
        foreach (preg_split('/\n/', $text) as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                $title = trim(strip_tags($m[1]));
                $number = preg_match('/^(\d+)\.\s+/', $title, $n) ? $n[1] : null;
                $id = Str::slug(Str::limit(preg_replace('/^\d+\.\s+/', '', $title), 60, ''));
                $id = ($number !== null ? $number.'-' : '').($id !== '' ? $id : 'section');
                while (isset($used[$id])) {
                    $id .= '-x';
                }
                $used[$id] = true;
                $sections[] = ['id' => $id, 'number' => $number, 'title' => $number !== null ? preg_replace('/^\d+\.\s+/', '', $title) : $title];
            }
        }

        $html = Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]);

        $i = 0;
        $html = preg_replace_callback('~<h2>(.*?)</h2>~s', function ($m) use (&$i, $sections) {
            $s = $sections[$i++] ?? null;
            if ($s === null) {
                return $m[0];
            }
            $label = $s['number'] !== null ? '<span class="lg-hn">'.$s['number'].'</span>'.e($s['title']) : e($s['title']);

            return '<h2 id="'.e($s['id']).'" class="lg-h2">'.$label.'<a class="lg-anchor" href="#'.e($s['id']).'" aria-label="Link to this section">#</a></h2>';
        }, $html);

        // Clause numbers: "1.1. text" becomes a hanging number.
        $html = preg_replace('~<p>(\d+(?:\.\d+)+)\.\s~', '<p class="lg-clause"><span class="lg-cn">$1</span>', $html);
        $html = preg_replace('~<blockquote>\s*(.*?)\s*</blockquote>~s', '<aside class="lg-notice" role="note">$1</aside>', $html);
        $html = preg_replace('~<table>(.*?)</table>~s', '<div class="lg-table-wrap" tabindex="0" role="region" aria-label="Table"><table>$1</table></div>', $html);
        $html = preg_replace('~\[VERIFY[^\]]*\]~u', '<mark class="lg-pending">$0</mark>', $html);

        return ['html' => $html, 'sections' => $sections, 'unresolved' => $this->unresolved($text)];
    }

    /** @return list<string> */
    public function unresolved(string $text): array
    {
        preg_match_all('~\[VERIFY[^\]]*\]~u', $text, $m);

        return $m[0];
    }

    /** @param array<string, array{label: string, value: ?string}> $facts */
    public function substitute(string $text, array $facts): string
    {
        return preg_replace_callback('~\{\{([a-z_]+)\}\}~', function ($m) use ($facts) {
            $fact = $facts[$m[1]] ?? null;
            $value = is_array($fact) ? trim((string) ($fact['value'] ?? '')) : '';
            if ($value === '') {
                return '[VERIFY: '.($fact['label'] ?? $m[1]).']';
            }

            // Escape markdown and HTML metacharacters: a fact is data, never markup.
            return preg_replace('~([\\\\`*_{}\[\]<>()#+!|])~', '\\\\$1', htmlspecialchars($value, ENT_NOQUOTES));
        }, $text);
    }
}
