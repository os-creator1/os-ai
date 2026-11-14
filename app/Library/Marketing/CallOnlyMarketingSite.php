<?php

namespace App\Library\Marketing;

use App\Library\AgencySite\SiteContentBuilder;
use Illuminate\Support\Arr;

/**
 * The official MotionGrove marketing site, presented as a "Book a call" landing page (/photobooth).
 *
 * It is the same read model the platform's own site uses, rendered through the same views, CSS and JavaScript, with
 * the platform's own content tree (including its photo-booth / event sample data). The ONLY differences are the ones
 * a booking landing page needs: no pricing, plans, comparison or self-service signup anywhere, and every conversion
 * button goes to MotionGrove's existing demo-booking destination.
 *
 * It is request-scoped and read-only: it works on a COPY of config('marketing'), never writes config, and is never
 * bound into the container, so the normal homepage, /pricing and signup routes cannot see it.
 */
final class CallOnlyMarketingSite extends MarketingSite
{
    /**
     * Where each normal-site page lives on this landing page: the home section that covers it, and the element id of
     * that section's heading. The normal pages carry pricing and signup, so navigation points at these instead; an item
     * whose section is not rendered is hidden rather than left as a dead anchor.
     */
    private const SECTION_ANCHORS = [
        'product' => ['capabilities', 'cap-h'],
        'solutions' => ['trust', 'trust-h'],
        'how-it-works' => ['how', 'how-h'],
        'customers' => ['videos', 'vid-h'],
    ];

    /** @var array<string, mixed> */
    private array $tree;

    /** @param  string  $landingPath  the landing page's own path, e.g. "photobooth" */
    public function __construct(private readonly string $landingPath)
    {
        $tree = SiteContentBuilder::enforceCallOnly((array) config('marketing'));

        // Reassurance ticks that mention plans are plan information too.
        $ticks = (array) Arr::get($tree, 'copy.home.hero.ticks', []);
        Arr::set($tree, 'copy.home.hero.ticks', array_values(array_filter(
            $ticks,
            fn ($tick) => preg_match(SiteContentBuilder::SELLING_WORDS, (string) $tick) !== 1,
        )));

        // The only plan-scoped copy left on the home page: the support note names "your plan" and "a subscription".
        Arr::set($tree, 'copy.home.people.note', '');

        $this->tree = $tree;
    }

    protected function cfg(string $key, mixed $default = null): mixed
    {
        return $key === '' ? $this->tree : Arr::get($this->tree, $key, $default);
    }

    /** A landing page is only worth serving when its one call to action works. */
    public function canBook(): bool
    {
        return $this->bookDemoUrl() !== null;
    }

    /** This IS MotionGrove's own site, so its Support center and Zoom guide are real pages. */
    public function hasPlatformSupportPages(): bool
    {
        return true;
    }

    public function sellsOnline(): bool
    {
        return false;
    }

    public function bookDemoLabel(): string
    {
        return 'Book a call';
    }

    public function getStartedLabel(): string
    {
        return 'Book a call';
    }

    public function pageVisible(string $key): bool
    {
        if ($key === 'pricing') {
            return false;
        }

        if (isset(self::SECTION_ANCHORS[$key]) && ! in_array(self::SECTION_ANCHORS[$key][0], $this->homeSections(), true)) {
            return false;
        }

        return parent::pageVisible($key);
    }

    /** Pricing and the plan comparison are sales tables; the setup commitment is a promise tied to a subscribed plan. */
    public function homeSections(): array
    {
        return array_values(array_diff(array_keys(self::HOME_SECTIONS), ['pricing', 'comparison', 'commitment']));
    }

    public function url(string $page): string
    {
        return match ($page) {
            'pricing' => $this->bookDemoUrl() ?? url($this->landingPath),
            'home' => url($this->landingPath),
            'product', 'solutions', 'how-it-works', 'customers' => url($this->landingPath).'#'.self::SECTION_ANCHORS[$page][1],
            default => parent::url($page),
        };
    }

    public function getStartedUrl(): string
    {
        return $this->url('pricing');
    }

    public function ctaUrl(string $slot): string
    {
        return $this->url('pricing');
    }

    public function planUrl(string $tier): string
    {
        return $this->url('pricing');
    }

    public function plans(): array
    {
        return [];
    }
}
