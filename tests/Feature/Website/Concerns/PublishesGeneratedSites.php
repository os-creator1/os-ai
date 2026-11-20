<?php

namespace Tests\Feature\Website\Concerns;

use App\Enums\Business\BusinessServiceStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\GuidedGeneration\GuidedGenerationCommitService;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use App\Models\WebsiteTemplate;
use Illuminate\Support\Str;

/**
 * Generate (fake AI only), publish and fetch a whole local-business website over its custom domain. The using test class
 * defines the DOMAIN and AREAS constants and seeds the wizard (DrivesSmartWizard) and fixtures (CreatesWebsiteFixtures).
 */
trait PublishesGeneratedSites
{
    /** A published, generated site: [Website, array<string, string> page slug => page_type]. */
    private function publishedSite(string $niche = 'booth', bool $withAreas = true, bool $withLocation = true, bool $publish = true): array
    {
        [$customer, $business] = $this->entitledTenant(['name' => $niche === 'booth' ? 'Harbor Booths' : 'Petal Tutoring', 'industry' => $niche === 'booth' ? 'photo_booth_service' : 'professional_services']);
        $names = $niche === 'booth' ? ['Magic Mirror Photo Booth', 'Open-Air Photo Booth', '360 Video Booth'] : ['SAT Preparation', 'Algebra Tutoring', 'Reading Support'];

        foreach ($names as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => Str::slug($name), 'description' => "What {$name} is: a specific, bookable service with its own details, requirements and outcomes for customers.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i + 1]);
        }

        if ($withLocation) {
            (new BusinessLocation())->forceFill(['business_id' => $business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'city' => 'Petal', 'region' => 'Mississippi', 'country_code' => 'US', 'service_area_cities' => ['Hattiesburg', 'Laurel', 'Biloxi']])->save();
        }
        \Illuminate\Support\Facades\DB::table('businesses')->where('id', $business->id)->update(['phone' => '6015550142', 'email' => 'hello@harborbooths.example']);

        // The owner has already let search engines in, so generated pages are born indexable.
        $website = $this->createWebsite($business->fresh(), ['name' => 'Harbor', 'indexing_released_at' => now()]);
        $template = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $this->fakeProvider();

        $attempt = app(GuidedGenerationCommitService::class)->generateFull($business->fresh(), $website, $template, $customer->user_id, 'idem-3b-' . $niche, null, null, null, null, $withAreas ? self::AREAS : null);
        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $attempt->status, (string) $attempt->failure_reason);

        if (! $publish) {
            return [$website->fresh(), $business->fresh()];
        }

        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
        $website->fresh()->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 'x', 'verified_at' => now(), 'activated_at' => now()]);

        return [$website->fresh(), $business->fresh()];
    }

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
                $sections = [$hero('Frequently asked questions'), ['type' => 'faq', 'data' => ['heading' => 'Before you book', 'items' => [['question' => 'How do I check availability?', 'answer' => 'Send the date and venue through the inquiry form and we will confirm. ' . substr($unique, 0, 12)]]]]];
                break;
            default:
                $sections = [$hero($label . ' ' . substr($unique, 0, 8))];
        }

        return ['page_key' => $key, 'title' => $label . ' ' . substr($unique, 0, 6), 'seo_title' => 'SEO ' . $label . ' ' . substr($unique, 0, 10), 'meta_description' => 'Meta ' . $label . ' ' . substr($unique, 0, 12), 'sections' => $sections];
    }

    private function url(string $path = '/'): string
    {
        return 'http://' . self::DOMAIN . $path;
    }

    /** @return array<string, string> slug => html for every page the published snapshot has */
    private function render(Website $website): array
    {
        $pages = [];

        foreach ($website->fresh()->publishedRevision->snapshot['pages'] ?? [] as $page) {
            $path = ! empty($page['is_home']) ? '/' : '/' . $page['slug'];
            $pages[$path] = $this->get($this->url($path))->assertOk()->getContent();
        }

        return $pages;
    }

    /** @return array<int, string> every href path of internal links in the body */
    private function links(string $html): array
    {
        preg_match_all('#href="(?:https://' . preg_quote(self::DOMAIN, '#') . ')?(/[^"\#?]*)"#', $html, $m);

        return array_values(array_unique($m[1]));
    }
}
