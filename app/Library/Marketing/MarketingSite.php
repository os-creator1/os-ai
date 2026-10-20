<?php

namespace App\Library\Marketing;

use Illuminate\Support\Facades\Route;

/**
 * Read model for the public marketing website (getmotiongrove.com).
 *
 * Every view under resources/views/marketing receives one of these as `$mg` and
 * asks it for copy, links and visibility. The data comes from config/marketing.php
 * today; an owner-only content editor can later swap content() for database rows
 * without touching a single view.
 *
 * It owns the rules that must hold in production whatever the content says:
 *   - one configurable "Book a demo" destination that is never a broken page;
 *   - plan prices come from the plan list only (never a trial or private offer);
 *   - an unconfirmed value (null) renders nothing, or a labelled placeholder
 *     only while `marketing.show_placeholders` is on.
 */
final class MarketingSite
{
    public const PAGES = ['home', 'product', 'solutions', 'how-it-works', 'customers', 'pricing'];

    /** @return array<string, mixed> */
    public function content(): array
    {
        return (array) config('marketing');
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return config('marketing.'.$key, $default);
    }

    public function showPlaceholders(): bool
    {
        return (bool) config('marketing.show_placeholders');
    }

    /** Section switch, e.g. section('home.trust_logos'). */
    public function section(string $key): bool
    {
        return (bool) config('marketing.sections.'.$key, true);
    }

    // ---------------------------------------------------------------- URLs

    public function url(string $page): string
    {
        // `/pricing` is served through the application's existing pricing route (see
        // ServeMarketingPricingToGuests), so it has no `marketing.pricing` route of its own.
        return match ($page) {
            'pricing' => url('pricing'),
            'book-demo' => route('demo.booking.show'),
            'home' => rtrim(route('marketing.home'), '/').'/',
            default => route('marketing.'.$page),
        };
    }

    /** Whether the public marketing site answers this request: enabled, and on an allowed host. */
    public function servesRequest(\Illuminate\Http\Request $request): bool
    {
        if (! config('marketing.enabled', true)) {
            return false;
        }

        // Blank MARKETING_HOSTS means the platform host only (the APP_URL host). TrustHosts also admits every
        // subdomain of APP_URL, and a customer domain that is not yet Active reaches routing unmatched, so
        // "any host" would publish the marketing site where it was never intended.
        $hosts = (array) config('marketing.hosts', []);
        if ($hosts === []) {
            $hosts = [(string) parse_url((string) config('app.url'), PHP_URL_HOST)];
        }

        return in_array(strtolower($request->getHost()), array_map('strtolower', $hosts), true);
    }

    /** Public asset under public/marketing, cache-busted by file time. */
    public function asset(string $path): string
    {
        $file = public_path('marketing/'.$path);
        $version = is_file($file) ? '?v='.filemtime($file) : '';

        return asset('marketing/'.$path).$version;
    }

    public function loginUrl(): string
    {
        $configured = $this->cleanUrl(config('marketing.cta.login_url'));

        return $configured ?? route('login');
    }

    /**
     * The single "Book a demo" destination, or null when none is operational.
     *
     * 1. An explicitly configured URL (MARKETING_BOOK_DEMO_URL) wins.
     * 2. Otherwise the native /book-demo page, only when it is genuinely usable.
     * 3. Otherwise null: callers must not render the button.
     */
    public function bookDemoUrl(): ?string
    {
        $configured = $this->cleanUrl(config('marketing.cta.book_demo_url'));

        if ($configured !== null) {
            return $configured;
        }

        return $this->nativeBookingReady() ? route('demo.booking.show') : null;
    }

    public function hasBookDemo(): bool
    {
        return $this->bookDemoUrl() !== null;
    }

    /**
     * The native booking page exists on this deployment AND is provisioned:
     * it 404s without a designated Booking Type and Turnstile keys (config/demo_booking.php),
     * so a button to it before then would be a broken journey.
     */
    public function nativeBookingReady(): bool
    {
        if (! Route::has('demo.booking.show')) {
            return false;
        }

        if (blank(config('demo_booking.booking_type_uuid'))) {
            return false;
        }

        $turnstile = (bool) config('demo_booking.turnstile.site_key') && (bool) config('demo_booking.turnstile.secret_key');
        $bypass = (bool) config('demo_booking.turnstile.bypass') && app()->environment(['local', 'testing']);

        return $turnstile || $bypass;
    }

    /** Destination for a plan's "Choose …" / "Get started" button. Never a trial. */
    public function planUrl(string $tier): string
    {
        $configured = $this->cleanUrl(config('marketing.cta.plans.'.$tier));

        if ($configured !== null) {
            return $configured;
        }

        if (Route::has('register')) {
            return route('register', ['plan' => $tier]);
        }

        return $this->bookDemoUrl() ?? $this->loginUrl();
    }

    /** Generic "Get started": the featured plan's destination. */
    public function getStartedUrl(): string
    {
        return $this->url('pricing');
    }

    // ------------------------------------------------------------ Content

    /** @return array<string, array<string, mixed>> plans in display order with a resolved `url`. */
    public function plans(): array
    {
        $plans = [];

        foreach ((array) config('marketing.plans') as $key => $plan) {
            $plans[$key] = $plan + ['key' => $key, 'url' => $this->planUrl($key)];
        }

        return $plans;
    }

    /** @return list<array{q: string, a: string, mobile: ?array{0: string, 1: string}, link: ?string}> */
    public function faqs(string $page): array
    {
        $out = [];

        foreach ((array) config('marketing.faqs.'.$page, []) as $faq) {
            $answer = $faq['a'] ?? null;

            if ($answer === null && $this->showPlaceholders()) {
                $answer = $faq['placeholder'] ?? null;
            }

            // An unconfirmed term is never published as fact.
            if ($answer === null) {
                continue;
            }

            $mobile = $faq['mobile'] ?? null;
            if (is_array($mobile) && $mobile[1] === null) {
                $mobile[1] = $answer;
            }

            $out[] = ['q' => $faq['q'], 'a' => $answer, 'mobile' => $mobile, 'link' => $faq['link'] ?? null];
        }

        return $out;
    }

    /** Industries with unverified feature rows removed. */
    public function industries(): array
    {
        $showUnverified = (bool) config('marketing.show_unverified_features');

        return array_map(function (array $industry) use ($showUnverified) {
            $industry['jobs'] = array_values(array_filter(
                $industry['jobs'],
                fn (array $job) => $showUnverified || empty($job['unverified'])
            ));

            return $industry;
        }, (array) config('marketing.industries'));
    }

    /** @return array<string, string> label => url, only entries that are configured and valid. */
    public function social(): array
    {
        $out = [];

        foreach (['youtube' => 'YouTube', 'instagram' => 'Instagram'] as $key => $label) {
            $url = $this->cleanUrl(config('marketing.social.'.$key));
            if ($url !== null) {
                $out[$label] = $url;
            }
        }

        return $out;
    }

    public function link(string $key): ?string
    {
        if ($key === 'support_email') {
            $email = trim((string) config('marketing.links.support_email'));

            return filter_var($email, FILTER_VALIDATE_EMAIL) ? 'mailto:'.$email : null;
        }

        return $this->cleanUrl(config('marketing.links.'.$key));
    }

    /** Help destination for the footer: help centre, else support email, else nothing. */
    public function helpUrl(): ?string
    {
        return $this->link('help_centre') ?? $this->link('support_email');
    }

    // -------------------------------------------------------- Testimonials

    /**
     * Renderable testimonial slots, ordered.
     *
     * A slot is real when it has a valid YouTube ID. Name, business, quote and context are optional; a real slot
     * without them shows neutral wording ("Client story N"), never an invented name. Anything else is a
     * placeholder, shown (clearly labelled) only while placeholders are enabled and hidden otherwise.
     *
     * @return array{featured: ?array, supporting: list<array>, all: list<array>, hasJazmin: bool, isEmpty: bool}
     */
    public function testimonials(): array
    {
        $slots = [];
        $showPlaceholders = $this->showPlaceholders();

        foreach ((array) config('marketing.testimonials') as $i => $t) {
            if (($t['visible'] ?? true) === false) {
                continue;
            }

            $id = $this->youtubeId($t['youtubeId'] ?? null);
            $real = $id !== null;

            if (! $real && ! $showPlaceholders) {
                continue;
            }

            $n = (int) ($t['order'] ?? $i + 1);
            // context: 'motiongrove', 'jazmin_media', or null when it has not been confirmed which it is.
            $context = in_array($t['context'] ?? null, ['motiongrove', 'jazmin_media'], true) ? $t['context'] : null;
            $short = ($t['format'] ?? 'standard') === 'short';
            $name = ! $real ? '[Customer name '.$n.']' : (filled($t['customerName'] ?? null) ? $t['customerName'] : 'Client story '.$n);

            $slots[] = [
                'id' => $t['id'] ?? 'story-'.$n,
                'order' => $n,
                'placeholder' => ! $real,
                'youtubeId' => $real ? $id : null,
                'thumb' => $real ? 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg' : null,
                'watchUrl' => $real ? ($short ? 'https://www.youtube.com/shorts/' : 'https://www.youtube.com/watch?v=').$id : null,
                'short' => $short,
                'embedUrl' => $real ? 'https://www.youtube-nocookie.com/embed/'.$id.'?autoplay=1' : null,
                'customerName' => $name,
                'businessName' => $real ? ($t['businessName'] ?? null) : '[Business]',
                'category' => $real ? ($t['category'] ?? null) : '[Category]',
                'location' => $real ? ($t['location'] ?? null) : '[City]',
                'quote' => $real ? ($t['quote'] ?? null) : '[Short quote, in the customer’s own words.]',
                'context' => $context ?? 'unspecified',
                'tag' => match ($context) {
                    'jazmin_media' => 'Jazmin Media client',
                    'motiongrove' => 'MotionGrove customer',
                    default => null,
                },
                'featured' => (bool) ($t['featured'] ?? false),
                'label' => $real ? null : 'VIDEO_ID_'.$n,
            ];
        }

        usort($slots, fn ($a, $b) => $a['order'] <=> $b['order']);

        $featured = null;
        foreach ($slots as $slot) {
            if ($slot['featured']) {
                $featured = $slot;
                break;
            }
        }
        $featured ??= $slots[0] ?? null;

        $supporting = array_values(array_filter($slots, fn ($s) => ! $featured || $s['id'] !== $featured['id']));

        return [
            'featured' => $featured,
            'supporting' => $supporting,
            'all' => $slots,
            'hasJazmin' => (bool) array_filter($slots, fn ($s) => $s['context'] === 'jazmin_media'),
            // Real stories whose origin is not confirmed: they must not read as MotionGrove software results.
            'hasUnspecified' => (bool) array_filter($slots, fn ($s) => $s['context'] === 'unspecified' && ! $s['placeholder']),
            'hasConfirmedContext' => (bool) array_filter($slots, fn ($s) => $s['context'] !== 'unspecified'),
            'isEmpty' => $slots === [],
        ];
    }

    public function youtubeId(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[A-Za-z0-9_-]{11}$/', $value) === 1 ? $value : null;
    }

    // ---------------------------------------------------------------- SEO

    /** @return array{title: string, description: string, canonical: string, image: string} */
    public function meta(string $page): array
    {
        $meta = (array) config('marketing.pages.'.$page);

        return [
            'title' => (string) ($meta['title'] ?? config('marketing.site.name')),
            'description' => (string) ($meta['description'] ?? ''),
            'canonical' => $this->url($page),
            'image' => $this->asset((string) config('marketing.site.og_image')),
        ];
    }

    /** Only well-formed absolute http(s) URLs (or root-relative paths) are ever rendered as links. */
    private function cleanUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//')) {
            return url($value);
        }

        $scheme = strtolower((string) parse_url($value, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($value, FILTER_VALIDATE_URL) ? $value : null;
    }
}
