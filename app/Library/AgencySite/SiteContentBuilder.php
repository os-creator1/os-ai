<?php

namespace App\Library\AgencySite;

use App\Models\WorkspacePlanCatalog;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * White Label — turns an Agency's saved answers (path => value) into the content tree the MotionGrove
 * marketing views read.
 *
 * Layers, lowest to highest: the MotionGrove tree → re-branded to the Agency's name → neutral Agency
 * defaults (SiteDefaults) → the Agency's own answers. A saved answer is re-validated here against
 * SiteSchema, so a tampered row can never put markup or an unknown key into a page.
 *
 * Prices, currencies, intervals and what each plan includes are NEVER taken from the answers: they come
 * from the plan catalog and the platform's own tier packaging, so editing the wording cannot change what
 * a visitor is charged or what the purchase unlocks.
 */
final class SiteContentBuilder
{
    /** Comparison rows that are about running the reseller business itself and must never appear on a resale plan. */
    private const NOT_RESOLD = ['agency_package_capabilities', 'white_label'];

    /**
     * @param  array<string, mixed>  $values  saved answers, path => value
     */
    public function site(array $values, SiteContext $context): AgencyMarketingSite
    {
        [$tree, $order] = $this->build($values, $context);

        return new AgencyMarketingSite($tree, $context, $order);
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>} the content tree and the home sections to render
     */
    public function build(array $values, SiteContext $context): array
    {
        $brand = $context->brandName;
        $tree = (array) config('marketing');

        // Never carry platform-only wiring into an Agency site.
        $tree['cta'] = ['book_demo_url' => null, 'login_url' => null, 'plans' => []];
        $tree['links'] = ['help_centre' => null, 'support_email' => null, 'rates' => null, 'privacy' => null, 'terms' => null, 'acceptable_use' => null];
        $tree['social'] = ['youtube' => null, 'instagram' => null];
        $tree['testimonials'] = [];
        $tree['trust_logos'] = [];
        $tree['plan_comparison'] = [];
        $tree['plans'] = [];
        $tree['hosts'] = [];
        $tree['sections'] = ['home' => ['trust_logos' => false, 'testimonials' => true, 'setup_specialist_card' => false]];
        $tree['show_unverified_features'] = false;

        $tree = $this->rebrand($tree, $brand);

        foreach (SiteDefaults::values($brand) as $path => $value) {
            Arr::set($tree, $path, $value);
        }

        $tree['faqs'] = ['home' => SiteDefaults::homeFaqs($brand), 'pricing' => SiteDefaults::pricingFaqs()];
        Arr::set($tree, 'pages.pricing.description', "Plans and pricing for {$brand}.");
        Arr::set($tree, 'pages.home.title', $brand);
        $tree['site'] = ['name' => $brand, 'legal_name' => $context->legalName, 'og_image' => 'img/mg-mark.png'];
        $tree['links']['support_email'] = $context->supportEmail;
        $tree['links']['help_centre'] = $context->supportUrl;

        // Page titles read "<Page> — <Brand>".
        foreach (SiteSchema::PAGES as $page) {
            if ($page !== 'home') {
                $title = (string) Arr::get($tree, "pages.$page.title", '');
                Arr::set($tree, "pages.$page.title", $title);
            }
        }

        $answers = $this->validAnswers($values);

        // Setup answers feed starter wording; the Agency's own answers below still win.
        if (! empty($answers['x.industries']) && ! array_key_exists('copy.home.trust.industries', $answers)) {
            Arr::set($tree, 'copy.home.trust.industries', $answers['x.industries']);
            Arr::set($tree, 'copy.home.trust.industries_mobile', array_slice($answers['x.industries'], 0, 5));
        }

        foreach ($answers as $path => $value) {
            if (str_starts_with($path, 'x.') || $value === null) {
                continue;
            }

            Arr::set($tree, $path, $this->shaped($tree, $path, $value));
        }

        // The "why one platform" table compares software; a row about help from people is a promise only the Agency can make.
        $tree['copy']['home']['comparison']['rows'] = array_values(array_filter(
            (array) Arr::get($tree, 'copy.home.comparison.rows', []),
            fn ($row) => ! in_array($row[0] ?? '', ['Setup & help'], true),
        ));

        $tree['industries'] = $this->industriesWithJobs($tree['industries'] ?? []);

        // x.* — settings of the Agency site itself, kept apart from the copy.
        $tree['x'] = ['cta' => [], 'testimonials' => (array) ($answers['x.testimonials'] ?? [])];
        foreach (['hero', 'final', 'product', 'solutions', 'how'] as $slot) {
            if (! empty($answers["x.cta.$slot"])) {
                $tree['x']['cta'][$slot] = $answers["x.cta.$slot"];
            }
        }

        // Product: order, visibility and the capability visitors see first.
        $productOrder = $this->orderedKeys($answers['x.product_order'] ?? [], SiteSchema::productFeatureKeys());
        if (! empty($answers['x.featured_feature'])) {
            $productOrder = array_values(array_unique(array_merge([$answers['x.featured_feature']], $productOrder)));
        }
        $tree['product_feature_order'] = $productOrder;
        foreach ((array) ($answers['x.product_hidden'] ?? []) as $hidden) {
            Arr::set($tree, 'sections.product.'.$hidden, false);
        }

        // Pages the Agency chose not to offer. Customers is hidden unless there is an approved story to show.
        $hiddenPages = (array) ($answers['x.pages_hidden'] ?? []);
        $hasStories = collect($tree['x']['testimonials'])->contains(fn ($t) => ! empty($t['approved']) && ($t['visible'] ?? true) && (! empty($t['quote']) || ! empty($t['video'])));
        if (! $hasStories) {
            $hiddenPages[] = 'customers';
        }
        foreach (array_unique($hiddenPages) as $page) {
            Arr::set($tree, "sections.pages.$page", false);
        }
        Arr::set($tree, 'sections.solutions.agency', false);
        Arr::set($tree, 'sections.how.commitment', (bool) ($answers['page_copy.how-it-works.commitment.lead'] ?? false) || (bool) ($answers['page_copy.how-it-works.commitment.cards'] ?? false));
        Arr::set($tree, 'sections.pricing.usage', false);

        // Plans and the comparison derived from what each tier really includes.
        $tree['plans'] = $this->planViews($context);
        $tree['plan_comparison'] = $this->comparison($context);

        $order = $this->homeOrder($answers, $context, $hasStories);

        return [$tree, $order];
    }

    /** Replace the product name in every string value (never in keys), and drop the original owner's story copy. */
    private function rebrand(array $tree, string $brand): array
    {
        array_walk_recursive($tree, function (&$value) use ($brand): void {
            if (is_string($value)) {
                $value = str_replace(['MotionGrove', 'getmotiongrove.com'], [$brand, strtolower(str_replace(' ', '', $brand)).'.com'], $value);
            }
        });

        return $tree;
    }

    /**
     * @return array<string, mixed> path => sanitized value, invalid entries dropped
     */
    private function validAnswers(array $values): array
    {
        $out = [];

        foreach ($values as $path => $value) {
            try {
                $out[$path] = SiteSchema::sanitize((string) $path, $value);
            } catch (ValidationException) {
                // A row that no longer validates is ignored, never rendered.
            }
        }

        return $out;
    }

    /** Convert an edited list into the shape the views destructure, keeping unedited trailing positions from the default. */
    private function shaped(array $tree, string $path, mixed $value): mixed
    {
        $field = SiteSchema::fieldIndex()[$path] ?? null;

        if ($field === null || $field['type'] !== 'rows') {
            return $value;
        }

        $default = (array) Arr::get($tree, $path, []);
        $out = [];

        foreach (array_values($value) as $i => $row) {
            if ($field['shape'] === 'assoc') {
                if ($path === 'industries') {
                    $row['jobs'] = self::parseJobs((string) ($row['jobs'] ?? ''));
                }

                $out[] = $row;

                continue;
            }

            $base = is_array($default[$i] ?? null) ? array_values($default[$i]) : [];
            $tuple = [];

            foreach ($field['columns'] as [$position]) {
                $tuple[(int) $position] = $row[$position] ?? null;
            }

            ksort($tuple);
            $length = max(count($tuple), count($base));
            $merged = [];

            for ($p = 0; $p < $length; $p++) {
                $merged[$p] = array_key_exists($p, $tuple) ? $tuple[$p] : ($base[$p] ?? null);
            }

            $out[] = $merged;
        }

        return $out;
    }

    /** "Title :: Detail" lines → the [title, detail] pairs the Solutions page lists. At most 8, each bounded. */
    public static function parseJobs(string $text): array
    {
        $jobs = [];

        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            [$title, $detail] = array_pad(array_map('trim', explode('::', $line, 2)), 2, '');
            $jobs[] = [mb_substr($title, 0, 80), mb_substr($detail, 0, 200)];
        }

        return array_slice($jobs, 0, 8);
    }

    /** @param list<mixed> $industries */
    private function industriesWithJobs(array $industries): array
    {
        return array_values(array_map(function ($industry) {
            $industry = (array) $industry;
            $industry['jobs'] = array_values((array) ($industry['jobs'] ?? []));
            $industry += ['head' => $industry['name'] ?? '', 'body' => ''];

            return $industry;
        }, $industries));
    }

    /** @return list<string> stored order, then anything not mentioned, restricted to $allowed */
    private function orderedKeys(array $stored, array $allowed): array
    {
        $keys = array_values(array_filter(array_unique(array_map('strval', $stored)), fn ($k) => in_array($k, $allowed, true)));

        return array_values(array_unique(array_merge($keys, $allowed)));
    }

    private function homeOrder(array $answers, SiteContext $context, bool $hasStories): array
    {
        $order = $this->orderedKeys($answers['x.home_order'] ?? [], SiteSchema::HOME_ORDER_DEFAULT);
        $hidden = (array) ($answers['x.home_hidden'] ?? []);
        $shown = (array) ($answers['x.home_shown'] ?? []);

        foreach (SiteSchema::HOME_HIDDEN_BY_DEFAULT as $key) {
            if (! in_array($key, $shown, true)) {
                $hidden[] = $key;
            }
        }

        if (! $hasStories) {
            $hidden[] = 'videos';
        }

        if (array_key_exists('faqs.home', $answers) && $answers['faqs.home'] === []) {
            $hidden[] = 'faq';
        }

        // A pricing section or comparison table with no plan in it is not a section.
        if ($context->actionablePlans() === []) {
            array_push($hidden, 'pricing', 'comparison');
        }

        return array_values(array_filter($order, fn ($key) => ! in_array($key, $hidden, true)));
    }

    // ------------------------------------------------------------------ plans

    /** @return array<string, array<string, mixed>> */
    private function planViews(SiteContext $context): array
    {
        return collect($context->actionablePlans())->mapWithKeys(fn ($p) => [$p['uid'] => $p])->all();
    }

    /**
     * The comparison table: the MotionGrove rows that map to a catalog feature, ticked from what each plan's
     * tier actually includes. Rows with no catalog feature behind them (e.g. onboarding assistance) are left out.
     *
     * @return list<array{group: string, rows: list<array<int, mixed>>}>
     */
    private function comparison(SiteContext $context): array
    {
        if ($context->actionablePlans() === []) {
            return [];
        }

        $keys = (array) config('marketing.plan_feature_keys');
        $groups = [];

        foreach ((array) config('marketing.plan_comparison') as $group) {
            $rows = [];

            foreach ($group['rows'] as $row) {
                $featureKey = $keys[$row[0]] ?? null;

                if ($featureKey === null || in_array($featureKey, self::NOT_RESOLD, true)) {
                    continue;
                }

                $cells = array_map(fn ($p) => in_array($featureKey, self::featuresOfTier((string) $p['tier']), true), $context->actionablePlans());
                $rows[] = [str_replace('MotionGrove', $context->brandName, $row[0]), $cells] + (isset($row[2]) ? [2 => $row[2]] : []);
            }

            if ($rows !== []) {
                $groups[] = ['group' => $group['group'], 'rows' => $rows];
            }
        }

        return $groups;
    }

    /** Bullet text for a plan card, taken from the catalog's real packaging for its tier. */
    public static function defaultFeatureLabels(string $tier, string $brand): array
    {
        $keys = (array) config('marketing.plan_feature_keys');
        $labels = [];

        foreach ((array) config('marketing.plan_comparison') as $group) {
            foreach ($group['rows'] as $row) {
                $featureKey = $keys[$row[0]] ?? null;

                if ($featureKey !== null && ! in_array($featureKey, self::NOT_RESOLD, true) && in_array($featureKey, self::featuresOfTier($tier), true)) {
                    $labels[] = str_replace('MotionGrove', $brand, $row[0]);
                }
            }
        }

        return $labels;
    }

    /**
     * Catalog feature keys the tier includes (the platform's own packaging), read once per REQUEST.
     *
     * Deliberately not a process-lifetime `static`: the platform owner can change a tier's packaging at any time
     * (PlatformPlanAdministrator), and a long-lived process (queue worker, Octane, a test run) would keep serving the
     * old package. Under PHP-FPM a static does not outlive its request, but nothing here should rely on that. The
     * repository's own request-scoped memo (keyed on the Request object, flushed at queue-job boundaries) gives one
     * read per request and can never outlive the request it was made in.
     *
     * @return list<string>
     */
    public static function featuresOfTier(string $tier): array
    {
        return app(\App\Library\Support\RequestScopedCache::class)->remember('agency-site:tier-features:'.$tier, function () use ($tier): array {
            $catalog = WorkspacePlanCatalog::query()->where('tier', $tier)->first();

            return $catalog === null
                ? []
                : DB::table('workspace_plan_features')->where('workspace_plan_catalog_id', $catalog->id)->pluck('feature_key')->all();
        });
    }
}
