<?php

namespace App\Library\ExternalSite\Rebuild;

use App\Models\ExternalSitePage;
use Illuminate\Support\Collection;

/**
 * Turns the facts of an already-crawled external website into a REVIEWABLE rebuild plan. Pure and deterministic: no
 * network, no AI, no database writes, and it never reads or copies page bodies, images or reviews (the crawl never
 * stored them). It only classifies each audited page by its own URL path and title so the owner can decide what the
 * MotionGrove-hosted site should keep, and it records every candidate fact as UNCONFIRMED until the owner confirms it.
 *
 * Only live, indexable, audited HTML pages become rows. Redirects, errors, files, blocked, noindex and parameter
 * pages are counted, not planned.
 */
final class ExternalRebuildPlanner
{
    /** The Greater Toronto Area cities a photo booth business can reasonably have location pages for. Candidates only. */
    public const GTA_AREAS = ['Toronto', 'Etobicoke', 'North York', 'Vaughan', 'Mississauga'];

    public const ROLES = ['home', 'corporate', 'wedding', 'party', 'services', 'gallery', 'backdrops', 'contact', 'area', 'legal', 'blog', 'other'];

    /** A path the draft redirect map can hold (mirrors WebsiteRedirectManager). */
    public const REDIRECT_PATH = '#^[a-z0-9][a-z0-9\-_/]{0,118}$#';

    private const EVENT_PATTERNS = [
        'corporate' => '/\b(corporate|company|business|brand|trade[- ]?show|conference|office)\b/i',
        'wedding' => '/\b(wedding|bridal|engagement|reception)\b/i',
        'party' => '/\b(party|parties|birthday|sweet[- ]?16|prom|graduation|bar[- ]?mitzvah|bat[- ]?mitzvah|anniversary|holiday)\b/i',
    ];

    /** Words that make a title/description a CLAIM the owner must confirm rather than a copy-ready line. */
    private const CLAIM_PATTERN = '/(#\s?1\b|\bnumber one\b|\bbest\b|\btop[- ]rated\b|\baward|\bguarantee|\bcheapest\b|\blowest price\b|\blicensed\b|\binsured\b|\bcertified\b|\b\d+\+?\s?(years|reviews|events|clients|stars?)\b|\b\d(\.\d)?\s?(\/|out of)\s?5\b|★|%|\$\s?\d)/i';

    /**
     * @param  Collection<int, ExternalSitePage>  $pages
     * @return array{pages: list<array<string, mixed>>, facts: list<array<string, mixed>>, excluded: int}
     */
    public function build(Collection $pages): array
    {
        $rows = [];
        $excluded = 0;
        $seen = [];

        foreach ($pages as $page) {
            if (! $this->isPlannable($page)) {
                $excluded++;

                continue;
            }

            $raw = trim((string) parse_url((string) $page->url, PHP_URL_PATH), '/');
            $path = strtolower($raw);

            // Equivalent addresses (case, trailing slash) count once.
            if (isset($seen[$path])) {
                continue;
            }
            $seen[$path] = true;

            [$role, $area, $event] = $this->classify($path, (string) $page->title);
            $title = $this->clean((string) $page->title, 255);
            $meta = $this->clean((string) $page->meta_description, 400);

            $rows[] = [
                'key' => sha1($path),
                'path' => $path,
                'title' => $title,
                'meta_description' => $meta,
                'role' => $role,
                'area' => $area,
                'event' => $event,
                'redirectable' => $path === '' || preg_match(self::REDIRECT_PATH, $path) === 1,
                'case_changed' => $raw !== $path,
                'canonical_differs' => $this->canonicalDiffers($page),
                'claim_flags' => $this->claimFlags($title.' '.$meta),
                'in_sitemap' => (bool) $page->in_sitemap,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$a['path'] !== '', strlen($a['path']), $a['path']] <=> [$b['path'] !== '', strlen($b['path']), $b['path']]);

        return ['pages' => $rows, 'facts' => $this->facts($rows), 'excluded' => $excluded];
    }

    private function isPlannable(ExternalSitePage $page): bool
    {
        $status = (int) $page->http_status;

        return $page->isAuditedPage()
            && $status >= 200 && $status < 300
            && ! $page->noindex
            && (string) parse_url((string) $page->url, PHP_URL_QUERY) === '';
    }

    /** @return array{0: string, 1: ?string, 2: ?string} role, area, event */
    private function classify(string $path, string $title): array
    {
        $haystack = str_replace(['-', '_', '/'], ' ', $path);
        $text = $haystack.' '.$title;

        if ($path === '') {
            return ['home', null, null];
        }
        if (preg_match('/\b(privacy|terms|cookie|disclaimer|refund|policy|policies|legal)\b/i', $haystack) === 1) {
            return ['legal', null, null];
        }
        if (preg_match('/^(blog|news|articles?)(\/|$)/i', $path) === 1) {
            return ['blog', null, null];
        }
        if (preg_match('/\b(contact|book|booking|availability|quote|reserve|check)\b/i', $haystack) === 1) {
            return ['contact', null, null];
        }
        if (preg_match('/\b(backdrops?)\b/i', $haystack) === 1) {
            return ['backdrops', null, null];
        }
        if (preg_match('/\b(gallery|photos|portfolio)\b/i', $haystack) === 1) {
            return ['gallery', null, null];
        }
        foreach (self::EVENT_PATTERNS as $event => $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return [$event, $this->area($text), $event];
            }
        }
        if (preg_match('/\b(services?|experiences?|packages?|pricing|rates|options)\b/i', $haystack) === 1) {
            return ['services', null, null];
        }
        if (($area = $this->area($text)) !== null) {
            return ['area', $area, null];
        }

        return ['other', null, null];
    }

    private function area(string $text): ?string
    {
        foreach (self::GTA_AREAS as $area) {
            if (preg_match('/\b'.str_replace(' ', '[\s_-]+', preg_quote($area, '/')).'\b/i', $text) === 1) {
                return $area;
            }
        }

        return null;
    }

    private function canonicalDiffers(ExternalSitePage $page): bool
    {
        $canonical = trim((string) $page->canonical_url);

        if ($canonical === '') {
            return false;
        }

        return rtrim(strtolower((string) preg_replace('#^https?://(www\.)?#i', '', $canonical)), '/')
            !== rtrim(strtolower((string) preg_replace('#^https?://(www\.)?#i', '', (string) $page->url)), '/');
    }

    /** @return list<string> */
    private function claimFlags(string $text): array
    {
        return preg_match_all(self::CLAIM_PATTERN, $text, $m) > 0 ? array_values(array_unique(array_map('strtolower', $m[0]))) : [];
    }

    /**
     * Candidate facts only: each one is UNCONFIRMED and names the page it came from. Nothing here is a statement the
     * generated site may assert until the owner confirms it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function facts(array $rows): array
    {
        $facts = [];

        foreach ($rows as $row) {
            if ($row['event'] !== null && ! isset($facts['event:'.$row['event']])) {
                $facts['event:'.$row['event']] = ['key' => 'event:'.$row['event'], 'type' => 'event_type', 'label' => ucfirst($row['event']).' events', 'source_path' => $row['path'], 'confirmed' => false];
            }
            $areaKey = $row['area'] === null ? null : 'area:'.str_replace(' ', '-', strtolower($row['area']));
            if ($areaKey !== null && ! isset($facts[$areaKey])) {
                $facts[$areaKey] = ['key' => $areaKey, 'type' => 'service_area', 'label' => $row['area'], 'source_path' => $row['path'], 'confirmed' => false];
            }
        }

        return array_values($facts);
    }

    private function clean(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($value))), 0, $max);
    }
}
