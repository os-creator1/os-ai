<?php

namespace App\Library\GoogleAds\Attribution;

use App\Library\GoogleAds\GoogleAdsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Google Ads Module V1 contract §10 — the ONE place a visitor-supplied
 * attribution value is validated. Used for the URL query on arrival AND for
 * the values read back from a cookie at conversion time, so a forged cookie
 * is held to exactly the same rules as a forged URL.
 *
 * An invalid value is DROPPED, never stored raw. A "touch" exists only when at
 * least one click id or UTM survives. The landing page is the request PATH
 * only (no query string, no fragment). Nothing here identifies the visitor.
 */
final class AttributionParameters
{
    public const CLICK_IDS = ['gclid', 'gbraid', 'wbraid'];

    public const UTMS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /** @var array<string, string> only the fields that survived validation */
    private array $fields;

    /**
     * @param  array<string, string>  $fields
     */
    private function __construct(array $fields, private readonly ?string $landingPage, private readonly ?CarbonImmutable $capturedAt)
    {
        $this->fields = $fields;
    }

    /** The tags carried by this request's URL (query), landing on this request's path. */
    public static function fromRequest(Request $request, ?CarbonImmutable $now = null): self
    {
        $query = $request->query();

        return new self(
            self::sanitiseFields(is_array($query) ? $query : []),
            self::sanitiseLandingPage($request->getPathInfo()),
            $now ?? CarbonImmutable::now(),
        );
    }

    /**
     * Values read back from a cookie payload; every one is re-sanitised.
     *
     * @param  array<mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        $captured = $payload['t'] ?? null;

        return new self(
            self::sanitiseFields($payload),
            self::sanitiseLandingPage($payload['p'] ?? null),
            is_int($captured) && $captured > 0 ? CarbonImmutable::createFromTimestampUTC($captured) : null,
        );
    }

    /** True when at least one click id or UTM survived validation. */
    public function hasTouch(): bool
    {
        return $this->fields !== [];
    }

    /** @return array<string, ?string> all eight tag columns, null where absent */
    public function columns(): array
    {
        $columns = [];
        foreach ([...self::CLICK_IDS, ...self::UTMS] as $key) {
            $columns[$key] = $this->fields[$key] ?? null;
        }

        return $columns;
    }

    public function landingPage(): ?string
    {
        return $this->landingPage;
    }

    public function capturedAt(): ?CarbonImmutable
    {
        return $this->capturedAt;
    }

    /** Same tags and same landing page (arrival time is not part of "what the touch was"). */
    public function sameTouchAs(self $other): bool
    {
        return $this->columns() === $other->columns() && $this->landingPage === $other->landingPage;
    }

    /**
     * The cookie body: sanitised fields, landing path and arrival time only.
     * Kept inside a size budget so the encrypted cookie fits a browser's 4 KB
     * limit; the least useful UTM text is shed first if it would not.
     *
     * @return array<string, mixed>
     */
    public function toCookiePayload(int $budgetBytes = 1900): array
    {
        $payload = $this->fields;
        if ($this->landingPage !== null) {
            $payload['p'] = $this->landingPage;
        }
        $payload['t'] = ($this->capturedAt ?? CarbonImmutable::now())->getTimestamp();

        foreach (['utm_content', 'utm_term', 'utm_medium', 'utm_source', 'utm_campaign', 'p'] as $shed) {
            if (strlen((string) json_encode($payload)) <= $budgetBytes) {
                break;
            }
            unset($payload[$shed]);
        }

        return $payload;
    }

    /**
     * @param  array<mixed>  $source
     * @return array<string, string>
     */
    private static function sanitiseFields(array $source): array
    {
        $config = new GoogleAdsConfig;
        $fields = [];

        foreach (self::CLICK_IDS as $key) {
            $value = self::clickId($source[$key] ?? null, $config->attributionMaxLength('click_id'));
            if ($value !== null) {
                $fields[$key] = $value;
            }
        }

        foreach (self::UTMS as $key) {
            $value = self::text($source[$key] ?? null, $config->attributionMaxLength('utm'));
            if ($value !== null) {
                $fields[$key] = $value;
            }
        }

        return $fields;
    }

    private static function clickId(mixed $value, int $max): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return strlen($value) <= $max && preg_match('/\A[A-Za-z0-9_\-]{10,}\z/', $value) === 1 ? $value : null;
    }

    /** Printable text only: control / format / private-use characters are removed, then trimmed and length-limited. */
    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || $value === '' || strlen($value) > $max * 4 || ! mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $clean = preg_replace('/[\p{C}\p{Zl}\p{Zp}]+/u', '', $value);
        if (! is_string($clean)) {
            return null;
        }

        $clean = mb_substr(trim($clean), 0, $max);

        return $clean === '' ? null : $clean;
    }

    /** A path only: starts with "/", no query string, no fragment, printable ASCII, length-limited. */
    private static function sanitiseLandingPage(mixed $path): ?string
    {
        if (! is_string($path) || $path === '' || $path[0] !== '/') {
            return null;
        }

        $path = strtok($path, '?#');
        if (! is_string($path) || preg_match('/\A[\x21-\x7E]+\z/', $path) !== 1) {
            return null;
        }

        return substr($path, 0, (new GoogleAdsConfig)->attributionMaxLength('landing_page'));
    }
}
