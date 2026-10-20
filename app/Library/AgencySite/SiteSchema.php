<?php

namespace App\Library\AgencySite;

use Illuminate\Validation\ValidationException;

/**
 * White Label — the Landing Page questionnaire, as data.
 *
 * Everything the editor can change is declared here, once: the screen it lives on, the
 * plain-language question, the kind of answer and its bounds. The same declaration drives
 * the form the Agency sees AND the validation of what it submits, so an editor field and
 * its server-side rule can never drift apart, and a path that is not declared here can
 * never be written into a site (no free-form keys, no HTML, no script).
 *
 * A "path" is a dot path into the marketing content tree (see SiteContentBuilder), or a
 * path under `x.` for settings that belong to the Agency site itself rather than to the
 * copy (goal, booking type, section order…). Overrides are stored as path => value, so
 * "reset to default" is simply removing the path.
 */
final class SiteSchema
{
    public const STEPS = [
        1 => 'Business & website',
        2 => 'Home page',
        3 => 'Product page',
        4 => 'Solutions page',
        5 => 'How it works',
        6 => 'Customers',
        7 => 'Pricing',
        8 => 'Book a call',
        9 => 'Signup & payment',
        10 => 'Review & publish',
    ];

    public const PAGES = ['home', 'product', 'solutions', 'how-it-works', 'customers', 'pricing'];

    /** Where a visitor can be sent by a hero / final call-to-action. */
    public const CTA_TARGETS = ['signup' => 'Start signup', 'pricing' => 'See pricing', 'booking' => 'Book a call'];

    public const GOALS = ['both' => 'Sell subscriptions and book calls', 'subscriptions' => 'Sell subscriptions', 'book_call' => 'Book a call'];

    public const HOME_ORDER_DEFAULT = ['hero', 'trust', 'problem', 'system', 'capabilities', 'people', 'how', 'videos', 'comparison', 'pricing', 'commitment', 'faq', 'final_cta'];

    /** Sections an Agency may switch off. Header and footer always render: they carry navigation and legal links. */
    public const HOME_HIDEABLE = ['trust', 'problem', 'system', 'capabilities', 'people', 'how', 'videos', 'comparison', 'pricing', 'commitment', 'faq', 'final_cta'];

    /** Shown only once the Agency has deliberately written them: they describe what the Agency promises. */
    public const HOME_HIDDEN_BY_DEFAULT = ['people', 'commitment'];

    /** @return array{label: string, page: string} */
    public static function homeSectionLabels(): array
    {
        return [
            'header' => 'Header & navigation', 'hero' => 'Hero', 'trust' => 'Industries you serve', 'problem' => 'Customer problems',
            'system' => 'Connected workflow', 'capabilities' => 'Main capabilities', 'people' => 'Support & onboarding',
            'how' => 'How it works (summary)', 'videos' => 'Testimonials', 'comparison' => 'Feature comparison', 'pricing' => 'Pricing preview',
            'commitment' => 'Setup commitment', 'faq' => 'FAQ', 'final_cta' => 'Final call to action', 'footer' => 'Footer',
        ];
    }

    private static function text(string $path, string $label, int $max = 160, array $extra = []): array
    {
        return ['path' => $path, 'label' => $label, 'type' => 'text', 'max' => $max] + $extra;
    }

    private static function area(string $path, string $label, int $max = 600, array $extra = []): array
    {
        return ['path' => $path, 'label' => $label, 'type' => 'textarea', 'max' => $max] + $extra;
    }

    private static function strings(string $path, string $label, int $items, int $max = 120): array
    {
        return ['path' => $path, 'label' => $label, 'type' => 'strings', 'max' => $max, 'items' => $items];
    }

    /** @param array<int, array{0: string, 1: string}> $columns position/key => label */
    private static function rows(string $path, string $label, array $columns, int $items, string $shape = 'tuple', int $max = 400): array
    {
        return ['path' => $path, 'label' => $label, 'type' => 'rows', 'columns' => $columns, 'items' => $items, 'shape' => $shape, 'max' => $max];
    }

    private static function choice(string $path, string $label, array $options): array
    {
        return ['path' => $path, 'label' => $label, 'type' => 'choice', 'options' => $options];
    }

    /**
     * Every declared field, grouped by the "section" it belongs to. A group is the unit that can be
     * hidden, reordered (where it makes sense) and reset to its defaults.
     *
     * @return array<string, array{step: int, page: string, label: string, fields: list<array<string, mixed>>}>
     */
    public static function groups(): array
    {
        $cta = self::CTA_TARGETS;

        return [
            // ---------------------------------------------------------------- Screen 1
            'setup' => ['step' => 1, 'page' => 'home', 'label' => 'Business & website', 'fields' => [
                self::text('x.audience', 'Who is it for?', 160),
                self::strings('x.industries', 'Which industries do you serve?', 8, 60),
                self::text('x.language', 'Website language', 40),
                self::text('x.market', 'Market / country', 60),
                self::choice('x.goal', 'What is the main goal of your website?', self::GOALS),
            ]],
            // ---------------------------------------------------------------- Screen 2: the 15 home sections
            'header' => ['step' => 2, 'page' => 'home', 'label' => 'Header & navigation', 'fields' => [
                self::text('nav.product', 'Name for the Product link', 24),
                self::text('nav.solutions', 'Name for the Solutions link', 24),
                self::text('nav.how-it-works', 'Name for the How it works link', 24),
                self::text('nav.customers', 'Name for the Customers link', 24),
                self::text('nav.pricing', 'Name for the Pricing link', 24),
                self::text('nav.resources', 'Name for the Resources link', 24),
            ]],
            'hero' => ['step' => 2, 'page' => 'home', 'label' => 'Hero', 'fields' => [
                self::text('copy.home.hero.chip', 'Short line above the headline', 80),
                self::text('copy.home.hero.title', 'What is the main headline?', 80),
                self::text('copy.home.hero.title_accent', 'Highlighted second line of the headline', 80),
                self::area('copy.home.hero.lead', 'What is the supporting description?', 420),
                self::area('copy.home.hero.lead_mobile', 'Shorter description for phones', 260),
                self::text('copy.home.hero.primary', 'What should the primary button say?', 30),
                self::choice('x.cta.hero', 'Should it open signup, pricing or booking?', $cta),
                self::text('copy.home.hero.secondary', 'What should the secondary button say?', 30),
                self::strings('copy.home.hero.ticks', 'Reassurance points under the buttons (only claim what is true)', 3, 60),
            ]],
            'trust' => ['step' => 2, 'page' => 'home', 'label' => 'Industries you serve', 'fields' => [
                self::text('copy.home.trust.title', 'Heading', 120),
                self::strings('copy.home.trust.industries', 'Industry names to show', 8, 40),
            ]],
            'problem' => ['step' => 2, 'page' => 'home', 'label' => 'Customer problems', 'fields' => [
                self::text('copy.home.problem.eyebrow', 'Small label', 40),
                self::text('copy.home.problem.title', 'Heading', 140),
                self::rows('copy.home.problem.items', 'Problems your customers have', [['0', 'Problem'], ['1', 'Description']], 6),
                self::text('copy.home.problem.closing', 'Closing line', 120),
                self::text('copy.home.problem.closing_accent', 'Highlighted closing line', 120),
            ]],
            'system' => ['step' => 2, 'page' => 'home', 'label' => 'Connected workflow', 'fields' => [
                self::text('copy.home.system.eyebrow', 'Small label', 50),
                self::text('copy.home.system.title', 'Heading', 140),
                self::area('copy.home.system.lead', 'Introduction', 320),
            ]],
            'capabilities' => ['step' => 2, 'page' => 'home', 'label' => 'Main capabilities', 'fields' => [
                self::text('copy.home.capabilities.eyebrow', 'Small label', 40),
                self::text('copy.home.capabilities.title', 'Heading', 140),
                self::rows('capability_cards', 'Capability cards (name, tagline, description)', [['0', 'Name'], ['1', 'Tagline'], ['2', 'Description']], 6),
            ]],
            'people' => ['step' => 2, 'page' => 'home', 'label' => 'Support & onboarding', 'fields' => [
                self::text('copy.home.people.eyebrow', 'Small label', 50),
                self::text('copy.home.people.title', 'Heading', 140),
                self::area('copy.home.people.lead', 'What support do you really offer?', 420),
                self::rows('copy.home.people.points', 'Specific support you commit to', [['0', 'Point'], ['1', 'Detail']], 5),
                self::text('copy.home.people.note', 'Fine print', 160),
            ]],
            'how' => ['step' => 2, 'page' => 'home', 'label' => 'How it works (summary)', 'fields' => [
                self::text('copy.home.how.eyebrow', 'Small label', 40),
                self::text('copy.home.how.title', 'Heading', 140),
                self::text('copy.home.how.cta', 'Link text to the full page', 40),
            ]],
            'videos' => ['step' => 2, 'page' => 'home', 'label' => 'Testimonials', 'fields' => [
                self::text('copy.home.videos.eyebrow', 'Small label', 40),
                self::text('copy.home.videos.title', 'Heading', 140),
                self::text('copy.home.videos.cta', 'Link text', 40),
            ]],
            'comparison' => ['step' => 2, 'page' => 'home', 'label' => 'Feature comparison', 'fields' => [
                self::text('copy.home.comparison.eyebrow', 'Small label', 40),
                self::text('copy.home.comparison.title', 'Heading', 140),
                self::text('copy.home.comparison.note', 'Note under the table', 160),
            ]],
            'pricing' => ['step' => 2, 'page' => 'home', 'label' => 'Pricing preview', 'fields' => [
                self::text('copy.home.pricing.eyebrow', 'Small label', 40),
                self::text('copy.home.pricing.title', 'Heading', 100),
                self::text('copy.home.pricing.lead', 'Sub-heading', 200),
                self::text('copy.home.pricing.link', 'Link text to the pricing page', 60),
            ]],
            'commitment' => ['step' => 2, 'page' => 'home', 'label' => 'Setup commitment', 'fields' => [
                self::text('copy.home.commitment.eyebrow', 'Small label', 50),
                self::text('copy.home.commitment.title', 'Heading', 120),
                self::area('copy.home.commitment.lead', 'What exactly do you commit to?', 420),
                self::rows('copy.home.commitment.cards', 'Commitment details', [['0', 'Title'], ['1', 'Detail']], 4),
            ]],
            'faq' => ['step' => 2, 'page' => 'home', 'label' => 'FAQ', 'fields' => [
                self::text('copy.home.faq.eyebrow', 'Small label', 30),
                self::text('copy.home.faq.title', 'Heading', 100),
                self::rows('faqs.home', 'Questions and answers (add, edit, remove, reorder)', [['q', 'Question'], ['a', 'Answer']], 20, 'assoc', 800) + ['clearable' => true],
            ]],
            'final_cta' => ['step' => 2, 'page' => 'home', 'label' => 'Final call to action', 'fields' => [
                self::text('copy.home.final.title', 'Headline', 140),
                self::area('copy.home.final.lead', 'Supporting text', 300),
                self::text('copy.home.final.primary', 'Primary button', 30),
                self::choice('x.cta.final', 'Primary button opens', $cta),
                self::text('copy.home.final.secondary', 'Booking button label', 30),
            ]],
            'footer' => ['step' => 2, 'page' => 'home', 'label' => 'Footer', 'fields' => [
                self::area('copy.footer_blurb', 'Short description under your logo', 220),
                self::text('site.legal_name', 'Registered business name for the copyright line', 120),
                ['path' => 'links.privacy', 'label' => 'Privacy policy link (https://)', 'type' => 'url'],
                ['path' => 'links.terms', 'label' => 'Terms of service link (https://)', 'type' => 'url'],
                ['path' => 'links.help_centre', 'label' => 'Help centre link (https://)', 'type' => 'url'],
                self::area('x.disclosure', 'Legal or regulatory disclosure shown on the site (optional)', 400),
            ]],
            // ---------------------------------------------------------------- Screen 3
            'product' => ['step' => 3, 'page' => 'product', 'label' => 'Product page', 'fields' => [
                self::text('page_copy.product.eyebrow', 'Small label', 40),
                self::text('page_copy.product.title', 'Page headline', 100),
                self::text('page_copy.product.title_accent', 'Highlighted part of the headline', 60),
                self::area('page_copy.product.lead', 'Introduction', 400),
                self::text('page_copy.product.cta.title', 'Closing call to action heading', 120),
                self::text('page_copy.product.cta.secondary', 'Booking button label', 30),
            ]],
            // ---------------------------------------------------------------- Screen 4
            'solutions' => ['step' => 4, 'page' => 'solutions', 'label' => 'Solutions page', 'fields' => [
                self::text('page_copy.solutions.eyebrow', 'Small label', 40),
                self::text('page_copy.solutions.title', 'Page headline', 120),
                self::area('page_copy.solutions.lead', 'Introduction', 300),
                self::text('page_copy.solutions.note', 'Note under the industry picker', 160),
                self::rows('industries', 'Industries (rename, describe, reorder)', [['name', 'Industry name'], ['head', 'Headline'], ['body', 'Description'], ['jobs', 'Benefits / use cases — one per line as: Title :: Detail']], 8, 'assoc', 900),
                self::text('page_copy.solutions.cta.title', 'Closing call to action heading', 120),
                self::area('page_copy.solutions.cta.lead', 'Closing call to action text', 220),
            ]],
            // ---------------------------------------------------------------- Screen 5
            'how-it-works' => ['step' => 5, 'page' => 'how-it-works', 'label' => 'How it works', 'fields' => [
                self::text('page_copy.how-it-works.eyebrow', 'Small label', 40),
                self::text('page_copy.how-it-works.title', 'Page headline', 100),
                self::text('page_copy.how-it-works.title_accent', 'Highlighted part of the headline', 80),
                self::area('page_copy.how-it-works.lead', 'Introduction', 300),
                self::rows('page_copy.how-it-works.steps', 'The steps (name, description, what the customer does, what you do, milestone)', [['0', 'Step name'], ['1', 'Description'], ['2', 'Customer does'], ['3', 'You do'], ['4', 'Milestone label'], ['5', 'Milestone']], 4),
                self::text('page_copy.how-it-works.commitment.title', 'Onboarding assistance heading', 100),
                self::area('page_copy.how-it-works.commitment.lead', 'Onboarding assistance — what do you really provide?', 420),
                self::rows('page_copy.how-it-works.commitment.cards', 'Onboarding assistance details', [['0', 'Title'], ['1', 'Detail']], 4),
                self::text('page_copy.how-it-works.cta.title', 'Final call to action heading', 100),
                self::area('page_copy.how-it-works.cta.lead', 'Final call to action text', 220),
            ]],
            // ---------------------------------------------------------------- Screen 6 is the testimonial manager (see SiteSchema::testimonialFields)
            'customers' => ['step' => 6, 'page' => 'customers', 'label' => 'Customers page', 'fields' => [
                self::text('page_copy.customers.eyebrow', 'Small label', 40),
                self::text('page_copy.customers.title', 'Page headline', 100),
                self::area('page_copy.customers.lead', 'Introduction', 300),
                self::text('page_copy.customers.cta.title', 'Closing call to action heading', 100),
            ]],
            // ---------------------------------------------------------------- Screen 7
            'pricing' => ['step' => 7, 'page' => 'pricing', 'label' => 'Pricing page', 'fields' => [
                self::choice('x.pricing_mode', 'How should the pricing page work?', ['both' => 'Show subscriptions and booking', 'subscriptions' => 'Show prices and online checkout', 'book_call' => 'Hide prices, book a call']),
                self::text('page_copy.pricing.eyebrow', 'Small label', 40),
                self::text('page_copy.pricing.title', 'Page headline', 100),
                self::area('page_copy.pricing.lead', 'Introduction', 300),
                self::text('page_copy.pricing.compare.title', 'Comparison table heading', 60),
                self::text('page_copy.pricing.billing_faq', 'Billing questions heading', 60),
                self::rows('faqs.pricing', 'Pricing FAQ', [['q', 'Question'], ['a', 'Answer']], 12, 'assoc', 800) + ['clearable' => true],
            ]],
            // ---------------------------------------------------------------- Screen 10
            'seo' => ['step' => 10, 'page' => 'home', 'label' => 'SEO & sharing', 'fields' => [
                self::text('pages.home.title', 'Home page title (search results)', 70),
                self::area('pages.home.description', 'Home page description', 160),
                self::text('pages.product.title', 'Product page title', 70),
                self::area('pages.product.description', 'Product page description', 160),
                self::text('pages.solutions.title', 'Solutions page title', 70),
                self::area('pages.solutions.description', 'Solutions page description', 160),
                self::text('pages.how-it-works.title', 'How it works page title', 70),
                self::area('pages.how-it-works.description', 'How it works page description', 160),
                self::text('pages.customers.title', 'Customers page title', 70),
                self::area('pages.customers.description', 'Customers page description', 160),
                self::text('pages.pricing.title', 'Pricing page title', 70),
                self::area('pages.pricing.description', 'Pricing page description', 160),
                ['path' => 'social.youtube', 'label' => 'YouTube link (https://)', 'type' => 'url'],
                ['path' => 'social.instagram', 'label' => 'Instagram link (https://)', 'type' => 'url'],
            ]],
        ];
    }

    /** The product features that can be renamed, described, hidden and reordered. */
    public static function productFeatureKeys(): array
    {
        return ['website', 'crm', 'auto', 'book', 'seo', 'ai'];
    }

    /** @return array<string, array<string, mixed>> path => field, across every group. */
    public static function fieldIndex(): array
    {
        $index = [];

        foreach (self::groups() as $group) {
            foreach ($group['fields'] as $field) {
                $index[$field['path']] = $field;
            }
        }

        foreach (self::productFeatureKeys() as $key) {
            foreach ([['chip', 'text', 80], ['title', 'text', 120], ['lead', 'textarea', 400], ['nav', 'text', 30]] as [$part, $type, $max]) {
                $index["product_features.$key.$part"] = ['path' => "product_features.$key.$part", 'label' => $part, 'type' => $type, 'max' => $max];
            }

            $index["product_features.$key.bullets"] = ['path' => "product_features.$key.bullets", 'label' => 'bullets', 'type' => 'strings', 'max' => 120, 'items' => 6];
        }

        $index['x.home_order'] = ['path' => 'x.home_order', 'label' => 'Home order', 'type' => 'keys', 'allowed' => self::HOME_ORDER_DEFAULT];
        $index['x.home_hidden'] = ['path' => 'x.home_hidden', 'label' => 'Hidden home sections', 'type' => 'keys', 'allowed' => self::HOME_HIDEABLE];
        $index['x.home_shown'] = ['path' => 'x.home_shown', 'label' => 'Home sections turned on', 'type' => 'keys', 'allowed' => self::HOME_HIDDEN_BY_DEFAULT];
        $index['x.product_order'] = ['path' => 'x.product_order', 'label' => 'Product order', 'type' => 'keys', 'allowed' => self::productFeatureKeys()];
        $index['x.product_hidden'] = ['path' => 'x.product_hidden', 'label' => 'Hidden product features', 'type' => 'keys', 'allowed' => self::productFeatureKeys()];
        $index['x.pages_hidden'] = ['path' => 'x.pages_hidden', 'label' => 'Hidden pages', 'type' => 'keys', 'allowed' => ['solutions', 'customers']];
        $index['x.featured_feature'] = ['path' => 'x.featured_feature', 'label' => 'Featured capability', 'type' => 'choice', 'options' => array_combine(self::productFeatureKeys(), self::productFeatureKeys())];
        $index['x.booking_type_uid'] = ['path' => 'x.booking_type_uid', 'label' => 'Booking type', 'type' => 'uuid'];
        $index['x.plans'] = ['path' => 'x.plans', 'label' => 'Plan presentation', 'type' => 'plans'];
        $index['x.testimonials'] = ['path' => 'x.testimonials', 'label' => 'Testimonials', 'type' => 'testimonials'];
        $index['x.cta.product'] = ['path' => 'x.cta.product', 'label' => 'Product CTA', 'type' => 'choice', 'options' => self::CTA_TARGETS];
        $index['x.cta.solutions'] = ['path' => 'x.cta.solutions', 'label' => 'Solutions CTA', 'type' => 'choice', 'options' => self::CTA_TARGETS];
        $index['x.cta.how'] = ['path' => 'x.cta.how', 'label' => 'How it works CTA', 'type' => 'choice', 'options' => self::CTA_TARGETS];
        $index['x.seo_image'] = ['path' => 'x.seo_image', 'label' => 'Social image', 'type' => 'asset'];

        return $index;
    }

    /**
     * Validates one submitted value against its declared field and returns the normalized value.
     * Nothing outside the schema is accepted, markup is refused (not stripped), and every URL
     * must be absolute https.
     *
     * @throws ValidationException
     */
    public static function sanitize(string $path, mixed $value): mixed
    {
        $field = self::fieldIndex()[$path] ?? null;

        if ($field === null) {
            throw ValidationException::withMessages([$path => 'That setting does not exist.']);
        }

        $fail = fn (string $message) => throw ValidationException::withMessages([$path => $message]);

        return match ($field['type']) {
            'text', 'textarea' => self::plain($value, (int) $field['max'], $field['type'] === 'textarea', $fail),
            'url' => self::url($value, $fail),
            'strings' => self::strings_($value, $field, $fail),
            'rows' => self::rows_($value, $field, $fail),
            'choice' => self::choice_($value, $field['options'], $fail),
            'keys' => self::keys($value, $field['allowed'], $fail),
            'uuid' => $value === null || $value === '' ? null : (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value) === 1 ? strtolower((string) $value) : $fail('Choose a booking type from the list.')),
            'plans' => self::plans($value, $fail),
            'testimonials' => self::testimonials($value, $fail),
            'asset' => $value === null || $value === '' ? null : (is_string($value) && preg_match('#^images/branding/agency/[0-9a-f-]{36}/[A-Za-z0-9._-]+$#', $value) === 1 ? $value : $fail('That image is not valid.')),
        };
    }

    private static function plain(mixed $value, int $max, bool $multiline, callable $fail): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            $fail('Enter text.');
        }

        $text = trim((string) $value);
        $text = $multiline ? preg_replace("/[ \t]+/u", ' ', str_replace("\r\n", "\n", $text)) : trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if ($text === '') {
            return null;
        }

        if ($text !== strip_tags($text) || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $text) === 1) {
            $fail('Use plain text only — no HTML or special characters.');
        }

        if (mb_strlen($text) > $max) {
            $fail("Keep this to {$max} characters or fewer.");
        }

        return $text;
    }

    private static function url(mixed $value, callable $fail): ?string
    {
        $text = self::plain($value, 255, false, $fail);

        if ($text === null) {
            return null;
        }

        $parts = parse_url($text);

        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || ! str_contains($parts['host'], '.') || isset($parts['user']) || isset($parts['pass']) || filter_var($text, FILTER_VALIDATE_URL) === false) {
            $fail('Enter a full link starting with https://');
        }

        return $text;
    }

    private static function strings_(mixed $value, array $field, callable $fail): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $fail('Enter a list.');
        }

        $out = [];

        foreach ($value as $item) {
            $text = self::plain($item, (int) $field['max'], false, $fail);

            if ($text !== null) {
                $out[] = $text;
            }
        }

        if (count($out) > (int) $field['items']) {
            $fail("At most {$field['items']} items.");
        }

        return $out;
    }

    private static function rows_(mixed $value, array $field, callable $fail): ?array
    {
        if ($value === null) {
            return null;
        }

        if (! is_array($value)) {
            $fail('Enter a list.');
        }

        $out = [];
        $columns = array_map(fn ($c) => (string) $c[0], $field['columns']);

        foreach (array_values($value) as $row) {
            if (! is_array($row)) {
                $fail('Each entry needs its fields.');
            }

            $clean = [];
            $any = false;

            foreach ($columns as $column) {
                $cell = self::plain($row[$column] ?? null, (int) $field['max'], true, $fail);
                $clean[$column] = $cell;
                $any = $any || $cell !== null;
            }

            // A completely empty row is simply a removed row.
            if ($any) {
                if ($clean[$columns[0]] === null) {
                    $fail('Every entry needs its first field filled in.');
                }

                $out[] = $clean;
            }
        }

        if (count($out) > (int) $field['items']) {
            $fail("At most {$field['items']} entries.");
        }

        return $out;
    }

    private static function choice_(mixed $value, array $options, callable $fail): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && array_key_exists($value, $options) ? $value : $fail('Choose one of the options.');
    }

    private static function keys(mixed $value, array $allowed, callable $fail): array
    {
        if (! is_array($value)) {
            $fail('Invalid selection.');
        }

        $keys = array_values(array_unique(array_map('strval', $value)));

        foreach ($keys as $key) {
            if (! in_array($key, $allowed, true)) {
                $fail('Unknown section.');
            }
        }

        return $keys;
    }

    /**
     * Presentation of plans: ONLY marketing fields. The commercial truth (price, currency, interval, tier
     * and therefore entitlements, Stripe price) always comes from the plan record and is never accepted here.
     *
     * @return list<array{uid: string, visible: bool, featured: bool, badge: ?string, cta: ?string, tagline: ?string, features: list<string>}>
     */
    private static function plans(mixed $value, callable $fail): array
    {
        if (! is_array($value)) {
            $fail('Invalid plans.');
        }

        $out = [];

        foreach (array_values($value) as $row) {
            $uid = is_array($row) ? ($row['uid'] ?? null) : null;

            if (! is_string($uid) || preg_match('/^[0-9a-f-]{36}$/i', $uid) !== 1) {
                $fail('Invalid plan.');
            }

            $out[] = [
                'uid' => strtolower($uid),
                'visible' => filter_var($row['visible'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'featured' => filter_var($row['featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'badge' => self::plain($row['badge'] ?? null, 24, false, $fail),
                'cta' => self::plain($row['cta'] ?? null, 30, false, $fail),
                'tagline' => self::plain($row['tagline'] ?? null, 120, false, $fail),
                'features' => self::strings_($row['features'] ?? [], ['max' => 100, 'items' => 12], $fail) ?? [],
            ];
        }

        if (count($out) > 12) {
            $fail('At most 12 plans.');
        }

        return $out;
    }

    /**
     * @return list<array{id: string, customer_name: string, business_name: ?string, industry: ?string, quote: ?string, video: ?string, image: ?string, featured: bool, approved: bool, visible: bool}>
     */
    private static function testimonials(mixed $value, callable $fail): array
    {
        if (! is_array($value)) {
            $fail('Invalid testimonials.');
        }

        $out = [];

        foreach (array_values($value) as $i => $row) {
            if (! is_array($row)) {
                $fail('Invalid testimonial.');
            }

            $name = self::plain($row['customer_name'] ?? null, 80, false, $fail);

            if ($name === null) {
                continue;
            }

            $video = self::plain($row['video'] ?? null, 255, false, $fail);

            if ($video !== null && self::youtubeId($video) === null) {
                $fail('Use a YouTube link or video ID.');
            }

            $out[] = [
                'id' => 't'.($i + 1),
                'customer_name' => $name,
                'business_name' => self::plain($row['business_name'] ?? null, 80, false, $fail),
                'industry' => self::plain($row['industry'] ?? null, 60, false, $fail),
                'quote' => self::plain($row['quote'] ?? null, 400, true, $fail),
                'video' => $video,
                'image' => null,
                'featured' => filter_var($row['featured'] ?? false, FILTER_VALIDATE_BOOLEAN),
                // Nothing is shown publicly until the Agency confirms it holds permission to publish it.
                'approved' => filter_var($row['approved'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'visible' => filter_var($row['visible'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ];
        }

        if (count($out) > 12) {
            $fail('At most 12 testimonials.');
        }

        return $out;
    }

    /** An 11-character YouTube ID from an ID or a youtube.com / youtu.be / shorts URL. */
    public static function youtubeId(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $value) === 1) {
            return $value;
        }

        $parts = parse_url($value);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com'], true)) {
            return null;
        }

        $candidate = $host === 'youtu.be' ? ltrim((string) ($parts['path'] ?? ''), '/') : null;

        if ($candidate === null) {
            parse_str((string) ($parts['query'] ?? ''), $q);
            $candidate = $q['v'] ?? null;

            if ($candidate === null && preg_match('#^/(?:shorts|embed)/([A-Za-z0-9_-]{11})#', (string) ($parts['path'] ?? ''), $m) === 1) {
                $candidate = $m[1];
            }
        }

        return is_string($candidate) && preg_match('/^[A-Za-z0-9_-]{11}$/', $candidate) === 1 ? $candidate : null;
    }
}
