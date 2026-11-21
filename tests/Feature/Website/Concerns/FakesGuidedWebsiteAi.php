<?php

namespace Tests\Feature\Website\Concerns;

use App\Library\Website\WebsiteAiGenerationClient;

/**
 * The deterministic fake AI the website acceptance tests share: it answers the guided generation request with distinct, plain
 * pages built only from the request's own confirmed facts. Never a real provider; it proves structure, not copy quality.
 */
trait FakesGuidedWebsiteAi
{
    /** Typical model output: hero + services list (names only, as the schema allows) + copy built from the request's own facts. */
    private function fakeProvider(): void
    {
        $mock = \Mockery::mock(WebsiteAiGenerationClient::class);
        $mock->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $mock->shouldReceive('lastRefusalReason')->andReturn(null);
        $mock->shouldReceive('complete')->andReturnUsing(function (array $messages) {
            $payload = json_decode(collect($messages)->firstWhere('role', 'user')['content'], true);

            return json_encode(['pages' => array_map(fn (array $entry) => $this->page($entry, $payload), $payload['plan'])]);
        });
        $this->app->instance(WebsiteAiGenerationClient::class, $mock);
    }

    private function page(array $entry, array $payload): array
    {
        $context = $payload['site_context'] ?? [];
        $city = $context['primary_market']['city'] ?? null;
        $in = $city === null ? '' : ' in ' . $city;
        $contact = collect($payload['site_pages'] ?? [])->firstWhere('page_type', 'contact')['path'] ?? '/photo-booth-contact';
        $key = $entry['page_key'];
        $label = (string) ($entry['entity']['name'] ?? $entry['entity']['area'] ?? ucfirst(str_replace('_', ' ', $entry['page_type'])));
        $unique = hash('sha256', $key) . hash('sha256', $key . 'b');
        $allowsText = in_array('text', $entry['allowed_section_types'] ?? [], true);
        $copy = fn (string $heading, string $body) => $allowsText
            ? ['type' => 'text', 'data' => ['heading' => $heading, 'body' => $body]]
            : ['type' => 'image_text', 'data' => ['heading' => $heading, 'body' => $body, 'image' => null, 'image_position' => 'left']];
        $hero = fn (string $heading) => ['type' => 'hero', 'data' => ['heading' => $heading, 'subheading' => null, 'primary_cta' => ['label' => 'Check availability', 'url' => $contact], 'secondary_cta' => null]];

        switch (true) {
            case $entry['page_type'] === 'home':
                $services = array_column($context['services'] ?? [], 'name');
                $sections = [
                    $hero(($context['primary_service'] ?? 'Our services') . $in),
                    ['type' => 'services', 'data' => ['heading' => 'What we offer', 'items' => array_map(fn ($name) => ['name' => $name, 'description' => 'Book ' . $name . ' for your event.', 'price_label' => null], $services)]],
                    $copy('Where we work', trim(implode(' ', (array) ($payload['confirmed_facts']['differentiators'] ?? [])) . ' ') . 'We work with customers ' . ($context['areas_served'] ?? [] ? 'in ' . implode(', ', $context['areas_served']) : 'by arrangement') . '.'),
                    ['type' => 'faq', 'data' => ['heading' => 'Questions before you book', 'items' => [
                        ['question' => 'How do I check availability?', 'answer' => 'Send the date and venue through the inquiry form and we will confirm.'],
                        ['question' => 'How much space does the booth need?', 'answer' => 'Allow a clear area for the booth and one person beside it; ask us about your venue.'],
                        ['question' => 'How long does setup take?', 'answer' => 'Setup is quick; confirm your load-in window with us.'],
                    ]]],
                ];
                break;
            case isset($entry['entity']['area']):
                $facts = $entry['entity']['local_facts'] ?? [];
                $sections = [
                    $hero('Services in ' . $entry['entity']['area']),
                    $copy('Venues and setup in ' . $entry['entity']['area'], 'We have set up at ' . implode(' and ', $facts['venues'] ?? []) . '. ' . ($facts['logistics'] ?? '') . ' Ref ' . $unique),
                    $copy('Booking in ' . $entry['entity']['area'], 'Tell us the date and venue. Ref ' . substr($unique, 10)),
                ];
                break;
            case in_array($entry['page_type'], ['service_detail', 'event_detail'], true):
                $sections = [
                    $hero($label . $in),
                    $copy('What ' . $label . ' is', ($entry['entity']['description'] ?? $label) . ' Details: ' . $unique),
                    $copy('Booking ' . $label, 'Tell us the date and venue to confirm availability. Reference ' . substr($unique, 0, 20)),
                ];
                break;
            case $entry['page_type'] === 'services_overview':
                $sections = [
                    $hero('Our services' . $in),
                    ['type' => 'services', 'data' => ['heading' => 'What we offer', 'items' => array_map(fn ($service) => ['name' => $service['name'], 'description' => 'Book ' . $service['name'] . ' for your event.', 'price_label' => null], $entry['entity']['services'] ?? [])]],
                ];
                break;
            case $entry['page_type'] === 'about':
                $sections = [$hero('About ' . ($payload['site_context']['business_name'] ?? 'us')), $copy('Who we are', 'We are a local business' . ($city === null ? '' : ' based in ' . $city) . '. ' . $unique)];
                break;
            case $entry['page_type'] === 'faq':
                $sections = [$hero('Frequently asked questions'), ['type' => 'faq', 'data' => ['heading' => 'Before you book', 'items' => [
                    ['question' => 'How do I check availability?', 'answer' => 'Send the date and venue through the inquiry form and we will confirm. ' . substr($unique, 0, 12)],
                    ['question' => 'How much space does the booth need, and can it fit an awkward or narrow entrance at the venue we have chosen?', 'answer' => "Allow a clear area for the booth and one person to stand beside it.\n\nIf the entrance is narrow or the room is crowded, tell us the venue and we will confirm what fits before you book. A venue contact can usually answer this quickly."],
                    ['question' => 'How long does setup take?', 'answer' => 'Setup is quick; confirm your load-in window with us.'],
                    ['question' => 'Can guests share their photos?', 'answer' => 'Yes, sharing is part of the booth experience; ask us how it works for your event.'],
                ]]]];
                break;
            default:
                $sections = [$hero($label . ' ' . substr($unique, 0, 8))];
        }

        return ['page_key' => $key, 'title' => $label . ' ' . substr($unique, 0, 6), 'seo_title' => 'SEO ' . $label . ' ' . substr($unique, 0, 10), 'meta_description' => 'Meta ' . $label . ' ' . substr($unique, 0, 12), 'sections' => $sections];
    }
}
