<?php

namespace App\Library\WebsiteAnalytics;

use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\FormSubmission;
use App\Models\WebsiteAnalyticsCampaign;
use App\Models\WebsiteAnalyticsEvent;
use App\Models\WebsiteAnalyticsProperty;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The ONLY writer of website_analytics_events.
 *
 * TWO ways in, one rule: an event is stored only for a property that is live right now, and only once per dedupe key
 * (database-enforced), so a retry, a refresh or a replay is the same event.
 *
 *  1. Browser beacons (pageview, calendar_open, form_open, form_inquiry): sent by MotionGrove's own analytics script after the
 *     visitor's page is actually visible and consent (where configured) allows. The tenant is NEVER taken from the beacon —
 *     only its opaque property key, which maps to a tenant here. A beacon cannot claim a booking: that outcome is written only
 *     server-side by bookingConfirmed(), after the appointment row is committed.
 *  2. Server outcomes: bookingConfirmed() (after the booking is stored) — and form_inquiry, which the beacon may only report for
 *     a Forms submission that really exists for THIS property's Business.
 *
 * Nothing personal is stored: no IP address, no user agent, no query string, no contact detail. Bots, link-preview fetchers
 * and prefetches are dropped.
 */
final class AnalyticsRecorder
{
    public const CLIENT_KINDS = ['pageview', 'calendar_open', 'form_open', 'form_inquiry'];

    private const BOT = '~bot|crawl|spider|slurp|preview|facebookexternalhit|facebot|whatsapp|telegram|skype|discord|slack|linkedin|pinterest|embedly|quora|vkshare|bitly|twilio|telnyx|messagebird|vonage|sinch|bandwidth|headless|phantom|lighthouse|pingdom|uptime|curl/|wget|python|java/|go-http|okhttp|libwww|httpclient|scrapy|apache-httpclient|node-fetch|axios|postman|mediapartners|google-read|bingpreview|duckduck|baidu|yandex|sogou|applebot|petalbot|semrush|ahrefs|mj12|dotbot|zgrab|nmap~i';

    public function __construct(
        private readonly AnalyticsProperties $properties,
        private readonly AnalyticsCampaignLinks $campaigns,
    ) {}

    /** True when this request is a bot, a link-preview fetcher, a prefetch, or the visitor opted out (GPC/DNT). */
    public static function ignoredClient(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        if ($agent === '' || preg_match(self::BOT, $agent) === 1) {
            return true;
        }

        if (in_array(strtolower((string) $request->headers->get('Sec-Purpose')), ['prefetch', 'prefetch;prerender', 'prerender'], true)
            || strtolower((string) $request->headers->get('Purpose')) === 'prefetch'
            || strtolower((string) $request->headers->get('X-Moz')) === 'prefetch') {
            return true;
        }

        return $request->headers->get('Sec-GPC') === '1' || $request->headers->get('DNT') === '1';
    }

    /**
     * @param  array<string, mixed>  $payload  decoded beacon body
     * @return string 'recorded' | 'duplicate' | 'rejected'
     */
    public function recordBeacon(array $payload, Request $request): string
    {
        $property = $this->properties->findByKey($this->str($payload['k'] ?? null, 40));
        $kind = $this->str($payload['t'] ?? null, 24);

        if ($property === null || ! in_array($kind, self::CLIENT_KINDS, true) || ! $this->properties->isLive($property)) {
            return 'rejected';
        }

        $visitor = $this->hex($payload['v'] ?? null);
        $session = $this->hex($payload['s'] ?? null);
        $eventId = $this->hex($payload['e'] ?? null);
        $campaign = $this->campaigns->resolve($this->str($payload['c'] ?? null, 12), (int) $property->workspace_id);
        $path = $this->path($payload['p'] ?? null);

        if ($visitor === null || $session === null || $path === null) {
            return 'rejected';
        }

        $subjectType = $subjectId = null;

        switch ($kind) {
            case 'pageview':
                if ($eventId === null) {
                    return 'rejected';
                }
                $dedupe = 'pv:' . $eventId;
                break;
            case 'calendar_open':
                // The calendar's own booking type must belong to this property's business; once per visit.
                if (! $this->properties->ownsBookingType($property, $this->str($payload['bt'] ?? null, 40))) {
                    return 'rejected';
                }
                $dedupe = 'cal:' . $session;
                break;
            case 'form_open':
                if ($property->owner_type !== WebsiteAnalyticsProperty::OWNER_WEBSITE) {
                    return 'rejected';
                }
                $dedupe = 'fo:' . $session;
                break;
            default: // form_inquiry
                $submission = $this->submissionFor($property, $this->str($payload['sub'] ?? null, 40));

                if ($submission === null) {
                    return 'rejected';
                }
                $dedupe = 'fi:' . $submission->id;
                $subjectType = 'form_submission';
                $subjectId = (int) $submission->id;
        }

        return $this->store($property, $kind, $dedupe, [
            'visitor_id' => $visitor,
            'session_id' => $session,
            'path' => $path,
            'landing' => $this->landing($property, $path),
            'referrer_host' => $this->referrerHost($payload['r'] ?? null, $request, $property),
            'campaign_id' => $campaign?->id,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
        ]);
    }

    /**
     * A booking that is STORED. Called after the appointment commit; never able to fail the booking. Counted once per
     * appointment (the dedupe key), so retrying the request, replaying or refreshing the confirmation adds nothing.
     * Needs the analytics context the booking form carried (mg_pk …); a booking made with no context is simply not attributed.
     */
    public function bookingConfirmed(Request $request, Appointment $appointment, BookingType $type): void
    {
        try {
            $property = $this->properties->findByKey($this->str($request->input('mg_pk'), 40));

            if ($property === null || ! $this->properties->isLive($property) || ! $this->properties->ownsBookingType($property, (string) $type->public_booking_uuid)) {
                return;
            }

            $campaign = $this->campaigns->resolve($this->str($request->input('mg_c'), 12), (int) $property->workspace_id);

            $this->store($property, 'booking_confirmed', 'bk:' . $appointment->id, [
                'visitor_id' => $this->hex($request->input('mg_v')),
                'session_id' => $this->hex($request->input('mg_s')),
                'path' => null,
                'landing' => $this->landing($property, null),
                'referrer_host' => null,
                'campaign_id' => $campaign?->id,
                'subject_type' => 'appointment',
                'subject_id' => (int) $appointment->id,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @param array<string, mixed> $attributes */
    private function store(WebsiteAnalyticsProperty $property, string $kind, string $dedupe, array $attributes): string
    {
        try {
            WebsiteAnalyticsEvent::query()->create($attributes + [
                'property_id' => $property->id,
                'dedupe_key' => $dedupe,
                'kind' => $kind,
                'occurred_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return 'duplicate';
        }

        return 'recorded';
    }

    private function submissionFor(WebsiteAnalyticsProperty $property, ?string $uid): ?FormSubmission
    {
        if ($uid === null || ! Str::isUuid($uid) || $property->business_id === null || $property->owner_type !== WebsiteAnalyticsProperty::OWNER_WEBSITE) {
            return null;
        }

        // A STORED submission of THIS property's Business, from its Website-source form (the same verified signal the
        // Forms thank-you announces). Anything else — unknown, another tenant's, abandoned — is no inquiry.
        return FormSubmission::query()
            ->where('uid', strtolower($uid))
            ->where('business_id', $property->business_id)
            ->where('source', \App\Enums\Forms\FormDeploymentSource::Website->value)
            ->first();
    }

    /** The landing address an event belongs to: the official/hosted prefix of its path, else the property's own landing. */
    public function landing(WebsiteAnalyticsProperty $property, ?string $path): ?string
    {
        if ($property->owner_type !== WebsiteAnalyticsProperty::OWNER_AGENCY_SITE) {
            return null;
        }

        $site = \App\Models\AgencySite::query()->find($property->agency_site_id);

        return $site === null ? null : $this->properties->agencyLanding($site);
    }

    private function referrerHost(mixed $referrer, Request $request, WebsiteAnalyticsProperty $property): ?string
    {
        $host = is_string($referrer) && $referrer !== '' ? parse_url(mb_substr($referrer, 0, 500), PHP_URL_HOST) : null;

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        // Our own pages are not a "source": the platform and marketing hosts, and the measured website's OWN domains (a customer
        // domain's beacon is sent to the platform host, so the request host alone cannot tell on-site navigation apart).
        $own = array_filter([strtolower((string) $request->getHost()), strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST))]);
        $own = array_merge($own, array_map('strtolower', (array) config('marketing.hosts', [])));

        if ($property->owner_type === WebsiteAnalyticsProperty::OWNER_WEBSITE && $property->website_id !== null) {
            $own = array_merge($own, \App\Models\WebsiteDomain::query()->where('website_id', $property->website_id)->pluck('domain')->map(fn ($d) => strtolower((string) $d))->all());
        }

        foreach ($own as $candidate) {
            $bare = str_starts_with($candidate, 'www.') ? substr($candidate, 4) : $candidate;

            if ($host === $bare || $host === 'www.' . $bare) {
                return null;
            }
        }

        return mb_substr($host, 0, 120);
    }

    private function path(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || $value[0] !== '/' || str_starts_with($value, '//')) {
            return null;
        }

        $path = parse_url(mb_substr($value, 0, 600), PHP_URL_PATH);

        return is_string($path) && $path !== '' ? mb_substr($path, 0, 255) : null;
    }

    private function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[a-f0-9]{32}$/', $value) === 1 ? $value : null;
    }

    private function str(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max ? $value : null;
    }
}
