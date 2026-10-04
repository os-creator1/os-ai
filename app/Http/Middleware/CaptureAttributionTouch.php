<?php

namespace App\Http\Middleware;

use App\Library\GoogleAds\Attribution\AttributionCookie;
use App\Library\GoogleAds\Attribution\AttributionParameters;
use App\Library\GoogleAds\GoogleAdsConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Google Ads Module V1 contract §10 — first-party attribution capture on the
 * PUBLIC GET pages (website pages, public form, public booking page).
 *
 * Sets cookies only when ALL of these hold: capture is enabled, the visitor did
 * not send Sec-GPC: 1 or DNT: 1, the page answered successfully, and the URL
 * carries at least one valid click id or UTM. `bos_at_first` is written once
 * (never overwritten while a valid one exists); `bos_at_last` is rewritten on
 * every new tagged arrival. A visitor arriving untagged gets no cookie at all.
 */
class CaptureAttributionTouch
{
    public static function visitorOptedOut(Request $request): bool
    {
        return $request->headers->get('Sec-GPC') === '1' || $request->headers->get('DNT') === '1';
    }

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $this->capture($request, $response);
        } catch (\Throwable) {
            // Capture is best effort: it must never break the page.
        }

        return $response;
    }

    private function capture(Request $request, Response $response): void
    {
        if (! $request->isMethod('GET')
            || ! (new GoogleAdsConfig)->attributionCaptureEnabled()
            || self::visitorOptedOut($request)
            || $response->getStatusCode() !== 200) {
            return;
        }

        $touch = AttributionParameters::fromRequest($request);

        if (! $touch->hasTouch()) {
            return;
        }

        $secure = $request->isSecure();

        if (AttributionCookie::read($request, AttributionCookie::FIRST) === null) {
            $response->headers->setCookie(AttributionCookie::make(AttributionCookie::FIRST, $touch, $secure));
        }

        $response->headers->setCookie(AttributionCookie::make(AttributionCookie::LAST, $touch, $secure));

        // A response that now carries a visitor's cookie must never be shared by a cache.
        if ($response->headers->hasCacheControlDirective('public')) {
            $response->headers->removeCacheControlDirective('public');
            $response->headers->addCacheControlDirective('private');
        }
    }
}
