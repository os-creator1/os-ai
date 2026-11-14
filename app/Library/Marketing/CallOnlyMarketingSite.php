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
        return $key !== 'pricing' && parent::pageVisible($key);
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
