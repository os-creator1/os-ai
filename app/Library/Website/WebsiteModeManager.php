<?php

namespace App\Library\Website;

use App\Enums\Website\WebsiteMode;
use App\Library\Business\BusinessManager;
use App\Library\ExternalSite\ExternalSiteException;
use App\Library\ExternalSite\UrlGuard;
use App\Library\ExternalSite\UrlResolver;
use App\Models\Business;
use App\Models\Customer;
use App\Models\Website;
use Illuminate\Support\Facades\DB;

/**
 * External Website Audit Mode V1 — the ONE reader and writer of a Business's
 * primary website source (`businesses.website_mode`).
 *
 * The website ADDRESS is not stored here: it stays in `businesses.website_url`
 * (the single URL authority, with its derived `canonical_domain`), and it is
 * only ever written through BusinessManager, so every existing consumer of the
 * address — Documents, Google Business Profile comparison, Citations — keeps
 * agreeing with it.
 *
 * resolve(): the stored mode; a Business that predates the choice but already
 * has a hosted Website record is `hosted` (its experience must not change at
 * all); a Business with neither is `null` and gets asked.
 */
final class WebsiteModeManager
{
    public function __construct(
        private readonly BusinessManager $businesses,
        private readonly UrlGuard $guard,
    ) {
    }

    public function resolve(Business $business): ?WebsiteMode
    {
        $stored = WebsiteMode::tryFrom((string) $business->getRawOriginal('website_mode'));

        if ($stored !== null) {
            return $stored;
        }

        return Website::query()->where('business_id', $business->id)->exists() ? WebsiteMode::Hosted : null;
    }

    public function set(Business $business, WebsiteMode $mode): void
    {
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => $mode->value]);
        $business->forceFill(['website_mode' => $mode->value])->syncOriginal();
    }

    /**
     * Normalises what the owner typed into a public website address, or refuses.
     * Syntactic only (the crawler's UrlGuard re-validates, with name resolution, at fetch
     * time): http(s), a real hostname, no credentials, no IP literal, no private
     * naming.
     *
     * @throws ExternalSiteException
     */
    public function normalizeAddress(string $raw): string
    {
        $raw = trim($raw);

        if ($raw === '' || mb_strlen($raw) > 2048) {
            throw new ExternalSiteException('url_malformed');
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*://#i', $raw) !== 1) {
            $raw = 'https://'.$raw;
        }

        $typed = parse_url($raw);

        if ($typed === false || isset($typed['user']) || isset($typed['pass'])) {
            throw new ExternalSiteException($typed === false ? 'url_malformed' : 'credentials_in_url');
        }

        $normalized = UrlResolver::normalize($raw) ?? throw new ExternalSiteException('url_malformed');
        $parts = parse_url($normalized);

        $this->guard->normalizeHost((string) $parts['host']);

        // The owner enters the website, not a deep page: keep the origin.
        return ($parts['scheme']).'://'.($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '').'/';
    }

    /**
     * Sets the Business's website address through the canonical Business
     * update seam (only the account owner may; the seam refuses anyone else).
     */
    public function saveAddress(Customer $customer, Business $business, string $address): Business
    {
        return $this->businesses->updateOwnBusinessProfile($customer, $business, ['website_url' => $address]);
    }
}
