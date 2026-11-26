<?php

namespace App\Library\Website\Tracking;

use App\Models\Website;
use App\Models\WebsiteTrackingSetting;
use Illuminate\Validation\ValidationException;

/**
 * The ONLY reader and writer of website tracking settings (Website → Tracking).
 *
 * WHAT IS ALLOWED. Structured, format-validated identifiers (Google tag, Google Ads id + lead-conversion label, Meta Pixel id)
 * that MotionGrove's own loader turns into tags, plus an optional owner-provided head snippet. The AI never writes or invents
 * any of it: nothing here is reachable from generation.
 *
 * THE OWNER SNIPPET IS TRUSTED-OWNER CODE, held to a security policy:
 *  - length-limited (MAX_SNIPPET) and limited to <script>, <noscript>, <meta>, <link>, <style> and <img> markup — no
 *    iframes, forms, objects, comments, PHP, or document structure tags that could break out of <head>;
 *  - every external script / link / image must be https;
 *  - it is rendered ONLY on a published page served from the owner's own custom domain (never on the shared MotionGrove
 *    origin, which also hosts the dashboard), and never in a dashboard, editor or unpublished preview;
 *  - it is not executed before consent when the setting requires consent (see public/js/website-tracking.js).
 *
 * SCOPES. `*` is the whole site; any other scope_key is a stable page identity: `home`, the page slug, or `@blog` (the native
 * blog index and every article). Settings are keyed by that identity, not by a page row, so they survive regeneration.
 */
final class WebsiteTrackingSettings
{
    public const MAX_SNIPPET = 4000;

    public const BLOG_SCOPE = '@blog';

    public const ARTICLE_SCOPE_PREFIX = '@article:';

    public const HOME_SCOPE = 'home';

    private const ALLOWED_TAGS = ['script', 'noscript', 'meta', 'link', 'style', 'img'];

    /** The stable identity a page's own tracking settings are stored under. */
    public static function scopeKeyForPage(bool $isHome, ?string $slug, bool $isBlog = false): string
    {
        if ($isBlog) {
            return self::BLOG_SCOPE;
        }

        return $isHome ? self::HOME_SCOPE : trim((string) $slug, '/');
    }

    /** Stable identity: article UID survives slug changes. */
    public static function articleScopeKey(string $articleUid): string
    {
        return self::ARTICLE_SCOPE_PREFIX . $articleUid;
    }

    public function find(Website $website, string $scopeKey): ?WebsiteTrackingSetting
    {
        return WebsiteTrackingSetting::query()->where('website_id', $website->id)->where('scope_key', $scopeKey)->first();
    }

    /** @return \Illuminate\Support\Collection<string, WebsiteTrackingSetting> keyed by scope_key */
    public function all(Website $website)
    {
        return WebsiteTrackingSetting::query()->where('website_id', $website->id)->get()->keyBy('scope_key');
    }

    /**
     * Validates and stores one scope. Saving every field empty removes the row (nothing to track).
     *
     * @param  array<string, mixed>  $input
     * @throws ValidationException
     */
    public function save(Website $website, string $scopeKey, array $input): ?WebsiteTrackingSetting
    {
        $clean = $this->validated($input);

        $hasContent = $clean['google_tag_id'] !== null || $clean['google_ads_id'] !== null || $clean['meta_pixel_id'] !== null || $clean['head_snippet'] !== null;

        if (! $hasContent) {
            $this->remove($website, $scopeKey);

            return null;
        }

        return WebsiteTrackingSetting::query()->updateOrCreate(
            ['website_id' => $website->id, 'scope_key' => $scopeKey],
            $clean,
        );
    }

    public function remove(Website $website, string $scopeKey): void
    {
        WebsiteTrackingSetting::query()->where('website_id', $website->id)->where('scope_key', $scopeKey)->delete();
    }

    /**
     * What a published page should load, merged from the site-wide and page settings and de-duplicated, or null when nothing
     * applies. A draft/unpublished/preview render passes $published = false and always gets null.
     *
     * @return ?array{require_consent: bool, google_tag_ids: array<int, string>, ads_conversions: array<int, array{id: string, label: string}>, meta_pixel_ids: array<int, string>, snippets: array<int, string>}
     */
    public function effective(Website $website, string $pageScopeKey, bool $published, bool $ownDomain): ?array
    {
        if (! $published) {
            return null;
        }

        $scopeKeys = [WebsiteTrackingSetting::GLOBAL_SCOPE, $pageScopeKey];
        if (str_starts_with($pageScopeKey, self::ARTICLE_SCOPE_PREFIX)) {
            $scopeKeys[] = self::BLOG_SCOPE; // individual posts inherit blog-wide and global settings
        }

        $rows = WebsiteTrackingSetting::query()
            ->where('website_id', $website->id)
            ->whereIn('scope_key', array_unique($scopeKeys))
            ->where('is_enabled', true)
            ->orderByRaw('scope_key = ? desc', [WebsiteTrackingSetting::GLOBAL_SCOPE])
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $tags = [];
        $ads = [];
        $pixels = [];
        $snippets = [];

        foreach ($rows as $row) {
            foreach ([$row->google_tag_id, $row->google_ads_id] as $id) {
                if ($id !== null && $id !== '') {
                    $tags[$id] = $id;
                }
            }

            if ($row->google_ads_id !== null && $row->google_ads_lead_label !== null) {
                $ads[$row->google_ads_id . '/' . $row->google_ads_lead_label] = ['id' => $row->google_ads_id, 'label' => $row->google_ads_lead_label];
            }

            if ($row->meta_pixel_id !== null) {
                $pixels[$row->meta_pixel_id] = $row->meta_pixel_id;
            }

            // Owner code runs only on the owner's own domain; the shared platform origin gets structured tags only.
            if ($ownDomain && $row->head_snippet !== null && trim($row->head_snippet) !== '') {
                $snippets[hash('sha256', $row->head_snippet)] = $row->head_snippet;
            }
        }

        if ($tags === [] && $pixels === [] && $snippets === []) {
            return null;
        }

        return [
            'require_consent' => $rows->contains(fn (WebsiteTrackingSetting $row) => $row->require_consent),
            'google_tag_ids' => array_values($tags),
            'ads_conversions' => array_values($ads),
            'meta_pixel_ids' => array_values($pixels),
            'snippets' => array_values($snippets),
        ];
    }

    /**
     * Does this page's consent configuration require consent before anything is measured? The same rows `effective()` merges
     * (site-wide + this page), but answered even when no third-party tag is configured — native Analytics must respect the
     * owner's consent choice whether or not Google or Meta is connected. No enabled setting means no consent bar was
     * configured, so none is required.
     */
    public function requiresConsent(Website $website, string $pageScopeKey): bool
    {
        $scopeKeys = [WebsiteTrackingSetting::GLOBAL_SCOPE, $pageScopeKey];
        if (str_starts_with($pageScopeKey, self::ARTICLE_SCOPE_PREFIX)) {
            $scopeKeys[] = self::BLOG_SCOPE;
        }

        return WebsiteTrackingSetting::query()
            ->where('website_id', $website->id)
            ->whereIn('scope_key', array_unique($scopeKeys))
            ->where('is_enabled', true)
            ->where('require_consent', true)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     * @throws ValidationException
     */
    public function validated(array $input): array
    {
        $text = fn (string $key): ?string => ($value = trim((string) ($input[$key] ?? ''))) === '' ? null : $value;

        $tag = $text('google_tag_id');
        $adsId = $text('google_ads_id');
        $label = $text('google_ads_lead_label');
        $pixel = $text('meta_pixel_id');
        $snippet = $text('head_snippet');

        $errors = [];

        if ($tag !== null && ! preg_match('/^(G|GT|GTM)-[A-Z0-9]{4,20}$/', $tag)) {
            $errors['google_tag_id'] = 'Enter a Google tag or Analytics ID such as G-ABC123XYZ or GTM-ABC123.';
        }

        if ($adsId !== null && ! preg_match('/^AW-[0-9]{6,15}$/', $adsId)) {
            $errors['google_ads_id'] = 'Enter a Google Ads conversion ID such as AW-123456789.';
        }

        if ($label !== null && ! preg_match('/^[A-Za-z0-9_-]{6,64}$/', $label)) {
            $errors['google_ads_lead_label'] = 'The conversion label is letters, numbers, dashes and underscores only.';
        }

        if ($label !== null && $adsId === null) {
            $errors['google_ads_id'] = 'A conversion label needs its Google Ads conversion ID.';
        }

        if ($pixel !== null && ! preg_match('/^[0-9]{8,20}$/', $pixel)) {
            $errors['meta_pixel_id'] = 'A Meta Pixel ID is 8 to 20 digits.';
        }

        if ($snippet !== null && ($problem = $this->snippetProblem($snippet)) !== null) {
            $errors['head_snippet'] = $problem;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'is_enabled' => (bool) ($input['is_enabled'] ?? true),
            'require_consent' => (bool) ($input['require_consent'] ?? true),
            'google_tag_id' => $tag,
            'google_ads_id' => $adsId,
            'google_ads_lead_label' => $label,
            'meta_pixel_id' => $pixel,
            'head_snippet' => $snippet,
        ];
    }

    /** The reason an owner snippet is refused, or null when it satisfies the policy. */
    private function snippetProblem(string $snippet): ?string
    {
        if (mb_strlen($snippet) > self::MAX_SNIPPET) {
            return 'The custom code can be at most ' . self::MAX_SNIPPET . ' characters.';
        }

        if (preg_match('/<\?|<!--|<\s*\/?\s*(head|body|html|iframe|object|embed|form|base|frame)\b/i', $snippet)) {
            return 'Custom code can only contain script, noscript, meta, link, style and img tags — no iframes, forms, comments or page-structure tags.';
        }

        // Script bodies are code, not markup (`i<n` is not a tag): only the markup around them is checked tag by tag.
        $markup = preg_replace('#(<script\b[^>]*>).*?(</script\s*>)#is', '$1$2', $snippet) ?? $snippet;

        if (preg_match_all('/<\s*\/?\s*([a-z][a-z0-9-]*)/i', $markup, $tags)) {
            foreach ($tags[1] as $tag) {
                if (! in_array(strtolower($tag), self::ALLOWED_TAGS, true)) {
                    return "The tag <{$tag}> is not allowed in custom code.";
                }
            }
        }

        if (preg_match_all('/\b(?:src|href)\s*=\s*["\']?\s*([^"\'\s>]+)/i', $markup, $urls)) {
            foreach ($urls[1] as $url) {
                if (! str_starts_with(strtolower($url), 'https://')) {
                    return 'Every script, link and image address in custom code must start with https://.';
                }
            }
        }

        return null;
    }
}
