<?php

namespace App\Library\AgencySite;

use App\Library\Marketing\MarketingDemo;
use App\Library\Marketing\MarketingSite;
use Illuminate\Support\Arr;

/**
 * White Label — the MotionGrove marketing views, rendered for an Agency.
 *
 * It is the same read model the platform's own site uses (`$mg`), with its content tree, brand,
 * plans, booking link and call-to-action destinations swapped for the Agency's. The views do not
 * know the difference, which is how the Agency inherits the real MotionGrove design instead of a
 * lookalike.
 */
final class AgencyMarketingSite extends MarketingSite
{
    /**
     * @param  array<string, mixed>  $tree  the resolved content tree (SiteContentBuilder)
     * @param  list<string>  $homeOrder  home section keys to render, in order
     */
    public function __construct(
        private readonly array $tree,
        private readonly SiteContext $context,
        private readonly array $homeOrder,
    ) {
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return $key === '' ? $this->tree : Arr::get($this->tree, $key, $default);
    }

    public function context(): SiteContext
    {
        return $this->context;
    }

    public function showPlaceholders(): bool
    {
        return false;
    }

    public function servesRequest(\Illuminate\Http\Request $request): bool
    {
        return true;
    }

    // ------------------------------------------------------------- identity

    public function brandName(): string
    {
        return $this->context->brandName;
    }

    public function legalName(): string
    {
        return $this->context->legalName ?: $this->context->brandName;
    }

    /** A site that sells MotionGrove to an industry names the product, not the Agency's brand, in sentences about the software. */
    public function productName(): string
    {
        return MarketingDemo::known($this->cfg('x.demo')) ? 'MotionGrove' : parent::productName();
    }

    public function wordmark(bool $onDark = false): string
    {
        $logo = $onDark ? ($this->context->logoDark ?? $this->context->logo) : ($this->context->logo ?? $this->context->logoDark);

        return $logo ?? self::textLogo($this->context->brandName, $onDark ? '#FFFFFF' : '#181413');
    }

    public function mark(): string
    {
        return $this->context->logoSquare ?? self::initialMark($this->context->brandName, $this->context->primaryColor);
    }

    public function favicon(): string
    {
        return $this->context->favicon ?? $this->mark();
    }

    public function homeSections(): array
    {
        return $this->homeOrder;
    }

    public function asset(string $path): string
    {
        // The stylesheet is served per site so the Agency's colours apply; everything else is shared.
        return $path === 'css/marketing.css' ? $this->context->cssUrl : parent::asset($path);
    }

    // ----------------------------------------------------------------- URLs

    public function url(string $page): string
    {
        return match ($page) {
            'book-demo' => $this->bookDemoUrl() ?? ($this->context->pageUrl)('home'),
            default => ($this->context->pageUrl)($page),
        };
    }

    public function legalLinks(): array
    {
        return ['primary' => [], 'supporting' => []];
    }

    public function loginUrl(): string
    {
        return $this->context->loginUrl;
    }

    /** The Agency's own booking page for its selected booking type, or null when booking is off or invalid. */
    public function bookDemoUrl(): ?string
    {
        return $this->context->offersBooking() ? $this->context->bookingUrl : null;
    }

    public function nativeBookingReady(): bool
    {
        return $this->bookDemoUrl() !== null;
    }

    public function getStartedUrl(): string
    {
        return $this->ctaUrl('default');
    }

    public function ctaUrl(string $slot): string
    {
        $default = $this->context->goal === 'book_call' ? 'booking' : 'pricing';

        return $this->resolveTarget((string) Arr::get($this->tree, 'x.cta.'.$slot, $default));
    }

    /** signup | pricing | booking → a URL that always leads somewhere that works. */
    public function resolveTarget(string $target): string
    {
        $pricing = ($this->context->pageUrl)('pricing');

        return match ($target) {
            'booking' => $this->bookDemoUrl() ?? $pricing,
            'signup' => $this->signupDestination() ?? $pricing,
            default => $pricing,
        };
    }

    /** With exactly one plan on sale "start signup" goes straight to it; otherwise visitors choose on Pricing. */
    private function signupDestination(): ?string
    {
        if (! $this->context->offersSubscriptions()) {
            return null;
        }

        $sellable = array_values(array_filter($this->context->plans, fn ($p) => $p['sellable']));

        return count($sellable) === 1 ? ($this->context->signupUrl)((string) $sellable[0]['uid']) : null;
    }

    // --------------------------------------------------------------- plans

    public function plans(): array
    {
        $out = [];

        foreach ($this->context->actionablePlans() as $plan) {
            $subscribable = $this->context->offersSubscriptions() && $plan['sellable'];

            // actionablePlans() guarantees a plan that cannot be bought has a working booking page to send people to.
            $url = $subscribable
                ? ($this->context->signupUrl)((string) $plan['uid'])
                : (string) $this->bookDemoUrl();

            $out[$plan['uid']] = [
                'key' => $plan['uid'],
                'name' => $plan['name'],
                'featured' => $plan['featured'],
                'badge' => $plan['badge'],
                'tagline' => $plan['tagline'],
                'tagline_pricing' => $plan['tagline'],
                'tagline_mobile' => $plan['tagline'],
                'features' => $plan['features'],
                'features_pricing' => $plan['features'],
                'features_lead' => null,
                'price_label' => $this->context->pricingMode === 'book_call' ? null : $plan['price_label'],
                'cycle_label' => $plan['cycle_label'],
                'cta' => $subscribable ? $plan['cta'] : 'Book a call',
                'url' => $url,
            ];
        }

        return $out;
    }

    public function planUrl(string $tier): string
    {
        return ($this->context->pageUrl)('pricing');
    }

    // ------------------------------------------------------ testimonials

    /**
     * Only testimonials the Agency marked as approved (it holds permission to publish them) and that have a
     * quote or a video. MotionGrove's own stories are never reachable from here: the tree carries none.
     */
    public function testimonials(): array
    {
        $slots = [];

        foreach ((array) Arr::get($this->tree, 'x.testimonials', []) as $i => $t) {
            if (empty($t['approved']) || ($t['visible'] ?? true) === false) {
                continue;
            }

            $id = ! empty($t['video']) ? SiteSchema::youtubeId((string) $t['video']) : null;
            $quote = $t['quote'] ?? null;

            if ($id === null && $quote === null) {
                continue;
            }

            $slots[] = [
                'id' => (string) ($t['id'] ?? 't'.($i + 1)),
                'order' => $i + 1,
                'placeholder' => false,
                'youtubeId' => $id,
                'thumb' => $id ? 'https://i.ytimg.com/vi/'.$id.'/hqdefault.jpg' : null,
                'watchUrl' => $id ? 'https://www.youtube.com/watch?v='.$id : null,
                'short' => false,
                'embedUrl' => $id ? 'https://www.youtube-nocookie.com/embed/'.$id.'?autoplay=1' : null,
                'customerName' => (string) $t['customer_name'],
                'businessName' => $t['business_name'] ?? null,
                'category' => $t['industry'] ?? null,
                'location' => null,
                'quote' => $quote,
                'context' => 'unspecified',
                'tag' => null,
                'featured' => (bool) ($t['featured'] ?? false),
                'label' => null,
            ];
        }

        $featured = collect($slots)->first(fn ($s) => $s['featured']) ?? ($slots[0] ?? null);
        $supporting = array_values(array_filter($slots, fn ($s) => ! $featured || $s['id'] !== $featured['id']));

        return [
            'featured' => $featured,
            'supporting' => $supporting,
            'all' => $slots,
            'hasJazmin' => false,
            'hasUnspecified' => false,
            'hasConfirmedContext' => false,
            'isEmpty' => $slots === [],
        ];
    }

    public function meta(string $page): array
    {
        $meta = parent::meta($page);
        $meta['image'] = $this->context->logoSquare ?? $this->context->logo ?? $this->mark();

        return $meta;
    }

    public function social(): array
    {
        return array_merge(parent::social(), $this->context->social);
    }

    // ------------------------------------------------------------- helpers

    /** A wordmark drawn from the name when no logo is uploaded; long names are compressed to fit a phone header. */
    private static function textLogo(string $name, string $color): string
    {
        $name = mb_substr($name, 0, 28);
        $text = htmlspecialchars($name, ENT_QUOTES | ENT_XML1);
        $natural = (int) (mb_strlen($name) * 14);
        $width = max(60, min(180, $natural));
        $fit = $natural > 180 ? ' textLength="'.($width - 2).'" lengthAdjust="spacingAndGlyphs"' : '';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$width.' 34" width="'.$width.'" height="34"><text x="0" y="25"'.$fit.' font-family="Figtree,Arial,sans-serif" font-size="24" font-weight="800" fill="'.$color.'">'.$text.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private static function initialMark(string $name, string $color): string
    {
        $initial = htmlspecialchars(mb_strtoupper(mb_substr(trim($name), 0, 1)) ?: 'A', ENT_QUOTES | ENT_XML1);
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="64" height="64"><rect width="64" height="64" rx="14" fill="'.$color.'"/><text x="32" y="44" text-anchor="middle" font-family="Figtree,Arial,sans-serif" font-size="36" font-weight="800" fill="#FFFFFF">'.$initial.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
