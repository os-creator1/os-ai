<?php

namespace Tests\Support\WebsiteAcceptance;

use App\Library\Ai\Enums\AiRefusalReason;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Business;

/**
 * Website V1 full-site acceptance — a deterministic stand-in for the ONE
 * existing AI seam (WebsiteAiGenerationClient::complete()). No network, no
 * provider, no metered call: it answers the guided-generation request with
 * valid, realistic copy built ONLY from the facts the request itself
 * carries (the plan's entity facts) plus the fixture business — never an
 * invented price, service, award, address or review.
 *
 * Its purpose is NOT to judge AI writing quality. It exists so the
 * acceptance can prove what Website V1 DOES with valid AI output: the
 * validators, the media binding, the draft, the preview, the snapshot and
 * the public renderer. Live-AI copy quality stays unverified (see the
 * acceptance report).
 */
final class AcceptanceFakeAi extends WebsiteAiGenerationClient
{
    /** @var array<int, string> every request's page keys, for assertions */
    public array $requests = [];

    /** Bind this fake as THE Website AI client (the seam is a container singleton-by-instance). */
    public static function bind(\Illuminate\Contracts\Foundation\Application $app): self
    {
        $fake = new self($app->make(\App\Library\Ai\AiGateway::class), $app->make(\App\Library\Ai\AiModelRouter::class));
        $app->instance(WebsiteAiGenerationClient::class, $fake);

        return $fake;
    }

    public function lastRefusalReason(): ?AiRefusalReason
    {
        return null;
    }

    public function lastCallWasBudgetExhausted(): bool
    {
        return false;
    }

    public function complete(array $messages, Business $business, ?int $actorUserId = null, ?int $maxOutputTokens = null, ?string $idempotencyKey = null): ?string
    {
        $user = collect($messages)->firstWhere('role', 'user')['content'] ?? '{}';
        $payload = json_decode(substr($user, (int) strpos($user, '{')), true) ?? [];
        $plan = $payload['plan'] ?? [];
        $this->requests = array_column($plan, 'page_key');

        $pages = [];
        foreach ($plan as $index => $page) {
            $pages[] = $this->page($page, $index, $business);
        }

        return json_encode(['pages' => $pages], JSON_THROW_ON_ERROR);
    }

    /** Whole-word truncation to a length the validator accepts, ending on a full stop. */
    private static function fit(string $text, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $cut = mb_substr($text, 0, $max - 1);
        $cut = preg_replace('/\s+\S*$/u', '', $cut);

        return rtrim($cut, ' ,;:.') . '.';
    }

    /** @param  array<string, mixed>  $page */
    private function page(array $page, int $index, Business $business): array
    {
        $name = $business->name;
        // A name ending in "Co." must not produce "Co.." when a sentence ends with it.
        $brand = str_ends_with($name, '.') ? $name : $name . '.';
        $type = $page['page_type'];
        $entity = $page['entity'] ?? [];
        $allowed = $page['allowed_section_types'] ?? [];

        $hero = fn (string $heading, ?string $sub = null) => ['type' => 'hero', 'data' => ['heading' => $heading, 'subheading' => $sub, 'background_image' => null, 'primary_cta' => null, 'secondary_cta' => null]];
        $text = fn (string $heading, string $body) => ['type' => 'text', 'data' => ['heading' => $heading, 'body' => $body]];
        $cta = fn (string $heading, string $body) => ['type' => 'cta', 'data' => ['heading' => $heading, 'body' => $body, 'buttons' => [['label' => 'Check availability', 'url' => 'mailto:' . PhotoBoothFixture::EMAIL]]]];
        $faq = fn (array $items) => ['type' => 'faq', 'data' => ['heading' => 'Frequently asked questions', 'items' => $items]];
        $contact = ['type' => 'contact_details', 'data' => ['show_phone' => true, 'show_email' => true, 'show_address' => true]];
        $faqItems = array_map(fn ($f) => ['question' => $f['question'], 'answer' => $f['answer']], PhotoBoothFixture::faqs());

        switch ($type) {
            case 'home':
                $title = 'Photo booth rentals in Chicago';
                $seoTitle = 'Chicago Photo Booth Rentals for Weddings & Events';
                $meta = $name . ' brings Digital, Glam, 360 and Roaming photo booths to weddings, corporate events and parties across Chicago and the suburbs.';
                $sections = [
                    $hero('Photo booths your guests will line up for', 'Digital, Glam, 360 and Roaming booths with an attendant, professional lighting and instant sharing for events across Chicago and the suburbs.'),
                    ['type' => 'services', 'data' => ['heading' => 'Our photo booths', 'items' => array_map(fn ($b) => ['name' => $b['name'], 'description' => $b['description']], PhotoBoothFixture::booths())]],
                    ['type' => 'image_text', 'data' => ['heading' => 'Everything included with every booking', 'body' => 'A friendly attendant, professional lighting, unlimited digital captures, instant sharing by text, email or QR code and custom photo templates are part of every package.', 'image' => null, 'image_position' => 'right']],
                    ['type' => 'testimonials', 'data' => ['heading' => 'What clients say', 'items' => PhotoBoothFixture::testimonials()]],
                    $faq(array_slice($faqItems, 0, 3)),
                    $cta('Ready to check your date?', 'Tell us about your event and we will confirm availability.'),
                    $contact,
                ];
                break;

            case 'services_overview':
                $title = 'Photo booth services';
                $seoTitle = 'Photo Booth Services: Digital, Glam, 360 & Roaming';
                $meta = 'Compare the photo booths and event types ' . $name . ' offers, from the Digital Photo Booth to the 360 Photo Booth.';
                $sections = [
                    $hero('Photo booth services for every kind of event', 'Choose the booth that fits your guests, your venue and your style.'),
                    ['type' => 'services', 'data' => ['heading' => 'What we offer', 'items' => array_map(fn ($s) => ['name' => $s['name'], 'description' => $s['description'] ?? null], $entity['services'] ?? [])]],
                    $cta('Not sure which booth fits?', 'Tell us about your event and we will recommend one.'),
                    $contact,
                ];
                break;

            case 'service_detail':
                $service = $entity['name'] ?? 'Photo booth';
                $description = $entity['description'] ?? '';
                $title = $service;
                $seoTitle = $service . ' Rental in Chicago | ' . $name;
                $meta = 'Book the ' . $service . ' from ' . $name . ': ' . rtrim($description, '.') . '.';
                $sections = [
                    $hero($service, 'Everything included when you book the ' . $service . ' with ' . $brand),
                    $text('About the ' . $service, $description . ' Your booking includes an attendant, professional lighting and instant sharing, so you can enjoy the event while your guests enjoy the booth.'),
                    $faq([['question' => 'How early do you set up for the ' . $service . '?', 'answer' => 'We arrive about 45 minutes before your start time to set up and test everything.']]),
                    $cta('Book the ' . $service, 'Tell us your date and venue and we will confirm availability.'),
                    $contact,
                ];
                break;

            case 'packages':
                $title = 'Photo booth packages';
                $seoTitle = 'Photo Booth Packages & Pricing | ' . $name;
                $meta = 'See what the Essential, Signature and Luxe photo booth packages include, with clear pricing from ' . $name . '.';
                $sections = [
                    $hero('Photo booth packages', 'Clear pricing and what is included, so you can choose with confidence.'),
                    ['type' => 'services', 'data' => ['heading' => 'Choose your package', 'items' => array_map(fn ($p) => ['name' => $p['name'], 'description' => $p['description'] ?? null, 'price_label' => $p['price_label'] ?? null], $entity['packages'] ?? [])]],
                    $contact,
                ];
                break;

            case 'about':
                $title = 'About ' . $name;
                $seoTitle = 'About ' . $name . ' | Chicago Photo Booth Company';
                $meta = 'Meet the Chicago team behind ' . $name . ' and learn what comes with every photo booth booking.';
                $sections = [
                    $hero('About ' . $name, 'A Chicago team that brings modern photo booths to your celebration.'),
                    $text('Our story', $name . ' brings modern photo booths to weddings, corporate events, birthdays, graduations and brand activations.' . "\n\n" . 'Every booking includes a friendly attendant, professional lighting, unlimited digital captures and instant sharing, so you can enjoy your event while we handle the booth.'),
                    $contact,
                ];
                break;

            case 'faq':
                $title = 'Photo booth FAQ';
                $seoTitle = 'Photo Booth Rental Questions Answered | ' . $name;
                $meta = 'Answers about booking, setup, sharing and pricing for photo booth rentals from ' . $name . '.';
                $sections = [$hero('Photo booth questions, answered'), $faq($faqItems), $contact];
                break;

            case 'gallery':
                $title = 'Photo booth gallery';
                $seoTitle = 'Photo Booth Gallery: Real Events | ' . $name;
                $meta = 'Browse photos from real weddings, corporate events and parties photographed by ' . $name . '.';
                $sections = [$hero('Photo booth gallery', 'A look at the booths and moments from our events.'), $contact];
                break;

            case 'contact':
                $title = 'Contact ' . $name;
                $seoTitle = 'Contact ' . $name . ' | Check Your Date';
                $meta = 'Call, email or send a request to check your date with ' . $name . ' for your Chicago-area event.';
                $sections = [$hero('Check your date', 'Tell us about your event and we will reply with availability.'), $contact];
                break;

            case 'location':
                $area = $entity['area'] ?? ($entity['city'] ?? 'your area');
                $title = 'Photo booth rentals in ' . $area;
                $seoTitle = 'Photo Booth Rental in ' . $area . ', IL | ' . $name;
                $meta = 'Book a photo booth for your ' . $area . ' event with ' . $name . ': attendant, lighting and instant sharing included.';
                $sections = [
                    $hero('Photo booths for ' . $area . ' events', 'Serving ' . $area . ' and nearby communities with ' . $brand),
                    $text('Photo booths in ' . $area, $this->areaCopy($area, $entity, $index)),
                    $cta('Check your ' . $area . ' date', 'Tell us the venue and date and we will confirm availability.'),
                    $contact,
                ];
                break;

            default:
                $title = ucwords(str_replace(['_', '-'], ' ', $page['page_key']));
                $seoTitle = $title . ' | ' . $name;
                $meta = $title . ' from ' . $name . '.';
                $sections = [$hero($title), $contact];
        }

        // Only ever the section types the plan allows for this page.
        $sections = array_values(array_filter($sections, fn ($section) => in_array($section['type'], $allowed, true)));

        return ['page_key' => $page['page_key'], 'title' => $title, 'seo_title' => $seoTitle, 'meta_description' => self::fit($meta, 155), 'sections' => $sections];
    }

    /**
     * Genuinely different copy per area (the anti-doorway validator rejects
     * near-duplicates): paragraph templates are chosen by a stable hash of
     * the area, and the neighbouring areas and service list differ per area.
     * Only facts from the request are used — no invented landmarks.
     *
     * @param  array<string, mixed>  $entity
     */
    private function areaCopy(string $area, array $entity, int $index): string
    {
        $near = array_slice($entity['nearby_areas'] ?? [], 0, 4);
        $nearText = $near === [] ? 'the surrounding suburbs' : implode(', ', array_slice($near, 0, -1)) . (count($near) > 1 ? ' and ' : '') . end($near);
        $services = array_slice($entity['services'] ?? [], 0, 4);
        $serviceText = $services === [] ? 'our photo booths' : implode(', ', array_slice($services, 0, -1)) . (count($services) > 1 ? ' and ' : '') . end($services);
        $home = $entity['business_home_city'] ?? PhotoBoothFixture::CITY;

        $pool = [
            "Planning something in {$area}? Jazmin Photo Booth Co. brings the booth to you, so your guests can step in, strike a pose and share the result before the song ends.",
            $area === $home
                ? "We are based right here in {$home}, so we handle weddings, corporate events, birthdays, graduations and brand activations in {$area} every week, with an attendant who manages setup from start to finish."
                : "From {$home} we travel to {$area} for weddings, corporate events, birthdays, graduations and brand activations, and our attendant handles setup from start to finish.",
            "Hosts in {$area} can choose {$serviceText}, then add a custom photo template that matches the theme of the day.",
            "We also serve {$nearText}, which makes it easy to book the same booth for a second event close to {$area}.",
            "Every {$area} booking includes professional lighting, unlimited digital captures and instant sharing by text, email or QR code.",
            "Tell us your {$area} venue and date and we will confirm availability, explain the setup space we need and walk you through the package that fits.",
            "After the event, your private online gallery keeps every {$area} memory in one place, ready for guests to download.",
            "Whether your {$area} celebration has twenty guests or two hundred, we will recommend the booth layout that keeps the line moving.",
        ];

        // A stable, area-specific order and a stable, area-specific subset of five paragraphs.
        usort($pool, fn ($a, $b) => strcmp(md5($area . $a), md5($area . $b)));

        return implode("\n\n", array_slice($pool, 0, 5));
    }
}
