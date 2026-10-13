<?php

namespace App\Library\Website\Setup;

use App\Library\Catalog\CatalogFeatureList;
use App\Library\Catalog\CatalogMoney;
use App\Models\Business;
use App\Models\CatalogItem;

/**
 * Safe, useful default copy for the wizard's About and FAQ screens, so the
 * owner is never faced with blank boxes. Everything is written from facts the
 * owner has actually given (their business name, booths, events, areas, how
 * they price and how visitors book) and says nothing else: no years in
 * business, awards, staff names, guarantees, cancellation or travel policies,
 * response times or customer claims. No AI call, no cost, same result for the
 * same facts.
 */
final class WizardContentDefaults
{
    public const ABOUT_VARIANTS = 4;

    public function __construct(private readonly ServiceAreaSuggestions $areas)
    {
    }

    /**
     * @param  array<string, mixed>  $answers  the owner's saved answers
     */
    public function about(Business $business, array $answers, int $variant = 0): string
    {
        $facts = $this->facts($business, $answers);
        $variant = $variant < 0 ? 0 : $variant % self::ABOUT_VARIANTS;
        $name = $facts['name'];
        $where = $facts['city'] !== null ? ' in ' . $facts['city'] : '';

        $booths = $this->join($facts['booths']);
        $events = $this->join($facts['events']);

        $opening = match ($facts['tone']) {
            'playful' => [
                "{$name} brings the fun of a photo booth to your celebration{$where}.",
                "Laughs, props and unforgettable photos — that's what {$name} is about{$where}.",
                "At {$name}, every event is a chance to get your guests posing and smiling{$where}.",
                "{$name} is all about making events more fun{$where}, one photo at a time.",
            ],
            'elegant' => [
                "{$name} offers a refined photo booth experience{$where}.",
                "{$name} adds a polished, memorable touch to events{$where}.",
                "Thoughtful details and beautiful photos define the {$name} experience{$where}.",
                "{$name} brings an elegant photo experience to your event{$where}.",
            ],
            'bold' => [
                "{$name} brings big energy to events{$where}.",
                "{$name} turns events into moments people talk about{$where}.",
                "Make a statement with {$name}{$where}.",
                "{$name} delivers a high-energy photo experience{$where}.",
            ],
            default => [
                "{$name} is a friendly photo booth rental business{$where}.",
                "{$name} helps make your event feel warm, personal and fun{$where}.",
                "Welcome to {$name}{$where} — we love helping people celebrate.",
                "{$name} brings a welcoming photo experience to your event{$where}.",
            ],
        };

        $sentences = [$opening[$variant]];

        if ($booths !== '') {
            $sentences[] = $variant % 2 === 0
                ? "Our experiences include {$booths}."
                : "Choose from {$booths}.";
        }

        if ($events !== '') {
            $sentences[] = $variant % 2 === 0
                ? "We're a great fit for {$events}."
                : "People hire us for {$events}.";
        }

        $sentences[] = match ($facts['cta']) {
            'call' => $facts['phone'] !== null ? "Call us at {$facts['phone']} to talk through your event." : 'Get in touch to talk through your event.',
            'book_now' => 'Ready to reserve your date? Book now.',
            default => 'Tell us about your event and request a quote.',
        };

        return implode(' ', $sentences);
    }

    /**
     * The default FAQ: about six complete questions, each answered from the
     * owner's own confirmed facts.
     *
     * @param  array<string, mixed>  $answers
     * @return array<int, array{key: string, question: string, answer: string}>
     */
    public function faq(Business $business, array $answers): array
    {
        $facts = $this->facts($business, $answers);
        $items = [];
        $add = function (string $key, string $question, string $answer) use (&$items) {
            $items[] = ['key' => $key, 'question' => $question, 'answer' => $answer];
        };

        $events = $this->join($facts['events']);
        $add('faq_events', 'What kinds of events do you serve?', $events !== ''
            ? "We serve {$events}. Don't see your event listed? Get in touch and tell us about it."
            : 'We bring our photo booths to a wide range of events. Get in touch and tell us about yours.');

        $areas = array_slice($facts['areas'], 0, 6);
        $add('faq_areas', 'Where do you offer your services?', $areas !== []
            ? 'We serve ' . $this->join($areas) . '. Not sure if your event location is covered? Send us the address and we\'ll let you know.'
            : ($facts['city'] !== null ? "We're based in {$facts['city']}. Send us your event location and we'll let you know if we can be there." : 'Send us your event location and we\'ll let you know if we can be there.'));

        $add('faq_booking', 'How do I request a booking?', match ($facts['cta']) {
            'call' => $facts['phone'] !== null
                ? "Give us a call at {$facts['phone']} with your event date and details."
                : 'Contact us with your event date and details.',
            'book_now' => 'Use the Book Now button on this site and choose the booth and date you\'d like.',
            default => 'Fill out the quote request form on this site with your event date, location and a few details, and we\'ll follow up with you.',
        });

        $add('faq_cost', 'How much does a photo booth rental cost?', match ($facts['pricing']) {
            'fixed_packages' => $facts['packages'] !== []
                ? 'Our packages are listed with their prices on this site. Pricing for extras is listed with the add-ons. Contact us if you have a question about what fits your event.'
                : 'Our packages are listed with their prices on this site. Contact us if you have a question about what fits your event.',
            'starting_from' => $facts['lowest_price'] !== null
                ? "Pricing starts from {$facts['lowest_price']} and depends on the booth and your event. Request a quote for an exact price."
                : 'Pricing depends on the booth and your event. Request a quote for an exact price.',
            default => 'Every event is different, so we provide custom quotes. Tell us about your event and we\'ll put one together.',
        });

        $packages = array_slice($facts['packages'], 0, 5);
        $add('faq_included', 'What\'s included in your packages?', $packages !== []
            ? 'Each package lists what it includes: ' . $this->join(array_map(fn (array $p) => $p['name'], $packages)) . '. Ask us if you\'d like to talk through the details or customize one.'
            : 'What\'s included depends on the booth and your event. Tell us what you have in mind and we\'ll walk you through the options.');

        $add('faq_advance', 'How far in advance should I inquire?', 'As early as you can — popular dates, especially weekends, can fill up. You\'re welcome to get in touch with your date to check availability.');

        return $items;
    }

    /**
     * @param  array<string, mixed>  $answers
     * @return array{name: string, phone: ?string, city: ?string, tone: string, cta: string, pricing: ?string, booths: array<int, string>, events: array<int, string>, areas: array<int, string>, packages: array<int, array{name: string}>, lowest_price: ?string}
     */
    private function facts(Business $business, array $answers): array
    {
        $base = $this->areas->baseFor($business, $answers);

        $names = function (string $key, ?string $kind = null) use ($business, $answers): array {
            $rows = $answers[$key] ?? null;

            if (is_array($rows)) {
                return array_values(array_filter(array_map(
                    fn ($row) => is_array($row) && ($row['visible'] ?? true) ? trim((string) ($row['name'] ?? '')) : '',
                    $rows
                )));
            }

            return $kind === null ? [] : $business->services()
                ->where('status', 'active')->where('website_visible', true)->where('kind', $kind)
                ->orderBy('sort_order')->pluck('name')->all();
        };

        $uids = array_values(array_filter(array_map(fn ($e) => is_array($e) ? ($e['uid'] ?? null) : null, (array) ($answers['packages'] ?? []))));
        $packages = $uids === [] ? [] : CatalogItem::where('business_id', $business->id)->whereIn('uid', $uids)->get()
            ->map(fn (CatalogItem $item) => ['name' => $item->name, 'features' => CatalogFeatureList::split($item->description)[1]])->all();

        $prices = [];
        foreach ((array) ($answers['booth_services'] ?? []) as $row) {
            if (is_array($row) && isset($row['starting_price']) && $row['starting_price'] !== null && ! empty($row['currency_code'])) {
                $prices[] = ['amount' => (float) $row['starting_price'], 'currency' => (string) $row['currency_code']];
            }
        }
        usort($prices, fn ($a, $b) => $a['amount'] <=> $b['amount']);
        $lowest = $prices === [] ? null : CatalogMoney::format((int) round($prices[0]['amount'] * 100), $prices[0]['currency']);

        $saved = $answers['service_area_cities'] ?? null;
        $areaList = is_array($saved) ? array_values(array_filter(array_map('strval', $saved))) : (array) ($business->primaryLocation()->first()?->service_area_cities ?? []);

        return [
            'name' => trim((string) ($answers['business_name'] ?? $business->name)),
            'phone' => trim((string) ($answers['phone'] ?? $business->phone)) ?: null,
            'city' => $base['city'] ?? null,
            'tone' => in_array($answers['brand_personality'] ?? null, ['playful', 'elegant', 'bold', 'warm'], true) ? $answers['brand_personality'] : 'warm',
            'cta' => in_array($answers['primary_cta'] ?? null, ['quote_request', 'call', 'book_now'], true) ? $answers['primary_cta'] : 'quote_request',
            'pricing' => in_array($answers['pricing_strategy'] ?? null, ['fixed_packages', 'starting_from', 'custom_quote'], true) ? $answers['pricing_strategy'] : null,
            'booths' => $names('booth_services', 'service'),
            'events' => $names('event_types', 'event'),
            'areas' => $areaList,
            'packages' => $packages,
            'lowest_price' => $lowest,
        ];
    }

    /** "A, B and C" */
    private function join(array $items): string
    {
        $items = array_values(array_filter(array_map('trim', $items), fn ($i) => $i !== ''));

        return match (count($items)) {
            0 => '',
            1 => $items[0],
            2 => $items[0] . ' and ' . $items[1],
            default => implode(', ', array_slice($items, 0, -1)) . ' and ' . end($items),
        };
    }
}
