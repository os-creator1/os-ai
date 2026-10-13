<?php

namespace Tests\Feature\Website\Concerns;

use App\Models\QuestionnaireResponse;
use Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Testing\TestResponse;

/**
 * Drives the v3 one-question-per-page Photo Booth setup over HTTP, the way the
 * browser does (form posts that ask for JSON). Requires CreatesWebsiteFixtures.
 */
trait DrivesGuidedWizard
{
    protected $customer;

    protected $business;

    protected $workspace;

    protected function seedGuidedWizard(): void
    {
        $this->seed(WebsiteTemplateSeeder::class);
        $this->seed(PhotoboothWebsiteSetupQuestionnaireV3Seeder::class);
    }

    /**
     * @param  array<string, mixed>  $business  Business column overrides
     * @param  array<string, mixed>|null  $location  primary location columns (created/updated when given)
     */
    protected function tenant(array $business = [], ?array $location = null, bool $start = true): void
    {
        [$this->customer, $this->business, $this->workspace] = $this->entitledTenant($business);
        $this->authenticateAsCustomer($this->customer);

        if ($location !== null) {
            $existing = $this->business->primaryLocation()->first();

            if ($existing !== null) {
                $existing->forceFill($location)->save();
            } else {
                $created = $this->business->locations()->create($location + ['name' => 'Primary Location', 'service_mode' => 'service_area', 'country_code' => 'US']);
                $created->forceFill(['is_primary' => true])->save();
            }
        }

        if ($start) {
            $this->get($this->url('start'));
        }
    }

    protected function url(string $name, ?string $step = null): string
    {
        $params = [$this->workspace->uid, $this->business->uid];
        if ($step !== null) {
            $params[] = $step;
        }

        return route('customer.workspaces.businesses.website.setup.' . $name, $params);
    }

    protected function wizardSession(): QuestionnaireResponse
    {
        return QuestionnaireResponse::where('business_id', $this->business->id)->where('status', 'in_progress')->firstOrFail();
    }

    protected function jpost(string $url, array $data = []): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->post($url, $data);
    }

    protected function jget(string $url, array $query = []): TestResponse
    {
        return $this->withHeaders(['Accept' => 'application/json'])->getJson($url . ($query ? '?' . http_build_query($query) : ''));
    }

    /**
     * Continue on a SINGLE-step screen: `$payload` is that step's own fields (`value` / `items`).
     *
     * @param  array<string, mixed>  $payload
     */
    protected function continueStep(string $stepKey, array $payload): TestResponse
    {
        return $this->jpost($this->url('autosave', $stepKey), $payload + [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => [$stepKey],
        ]);
    }

    /** Continue on the grouped basics screen. */
    protected function continueBasics(array $values = []): TestResponse
    {
        $values += ['business_name' => 'Glow Booths', 'phone' => '555-0100', 'email' => 'hello@glowbooths.test'];

        return $this->jpost($this->url('autosave', 'business_name'), [
            'answers_revision' => $this->wizardSession()->answers_revision,
            '__present' => ['business_name', 'phone', 'email', 'logo'],
            's' => collect($values)->map(fn ($v) => ['value' => $v])->all(),
        ]);
    }

    /** Uploads a real PNG to the Business image store (the same endpoint the card's photo picker uses). */
    protected function uploadPhoto(string $name = 'photo', string $unique = ''): string
    {
        $bytes = $this->validPngBytes() . ($unique !== '' ? $unique : $name . microtime(true));
        $response = $this->jpost($this->url('backdrop-image.upload'), ['photo' => $this->fakeImageUpload($name . '.png', $bytes), 'name' => $name]);
        $response->assertOk();

        return (string) $response->json('path');
    }

    /** @return array<int, string> the visible screen keys, in order */
    protected function visibleScreens(): array
    {
        $session = $this->wizardSession();

        return array_map(fn (array $screen) => $screen[0]['key'], app(\App\Library\Website\Setup\QuestionnaireStepResolver::class)->screens($session->version->steps(), $session->answers ?? []));
    }

    /**
     * Uploads `$count` real photos to the website gallery through the wizard's own endpoint and makes the first one
     * the homepage cover. Returns their asset uids in order.
     *
     * @return array<int, string>
     */
    protected function uploadGalleryPhotos(int $count = 3): array
    {
        $uids = [];

        for ($i = 0; $i < $count; $i++) {
            $bytes = $this->validPngBytes() . 'gallery-' . $i . '-' . microtime(true);
            $response = $this->withHeaders(['Accept' => 'application/json'])->post($this->url('gallery.upload'), ['photos' => [$this->fakeImageUpload("gallery-{$i}.png", $bytes)]]);
            $response->assertOk();
            $uids[] = (string) $response->json('assets.0.uid');
        }

        if ($uids !== []) {
            $this->jpost(route('customer.workspaces.businesses.website.setup.gallery.update', [$this->workspace->uid, $this->business->uid, $uids[0]]), ['is_cover' => '1'])->assertOk();
        }

        return $uids;
    }

    /**
     * Walks the guided setup end to end with sensible answers, the way the browser does. Each key of `$o` replaces that
     * screen's default payload; `null` skips the screen.
     *
     * @param  array<string, mixed>  $o
     * @return array{booth_photos: array<string, string>, backdrop_photo: ?string}
     */
    protected function completeGuidedSetup(array $o = []): array
    {
        $boothPhoto = $this->uploadPhoto('open-air');
        $boothPhoto2 = $this->uploadPhoto('mirror');
        $backdropPhoto = $this->uploadPhoto('floral');

        $screens = [
            'business_name' => fn () => $this->continueBasics(),
            'base_location' => fn () => $this->continueStep('base_location', ['value' => ['city' => 'Irvine', 'region' => 'CA', 'country_code' => 'US']]),
            'service_area_cities' => fn () => $this->continueStep('service_area_cities', ['value' => ['Irvine, CA', 'Tustin, CA', 'Costa Mesa, CA']]),
            'booth_services' => fn () => $this->continueStep('booth_services', ['items' => [
                ['key' => 'k-open', 'name' => 'Open-Air Photo Booth', 'description' => 'A modern open booth.', 'preset' => 'open_air', 'visible' => '1', 'image_path' => $boothPhoto, 'price' => '399', 'currency_code' => 'USD'],
                ['key' => 'k-mirror', 'name' => 'Mirror Photo Booth', 'description' => 'An interactive mirror.', 'preset' => 'mirror', 'visible' => '1', 'image_path' => $boothPhoto2],
            ]]),
            'event_types' => fn () => $this->continueStep('event_types', ['items' => [
                ['key' => 'e-w', 'name' => 'Weddings', 'description' => 'Wedding fun.', 'preset' => 'weddings', 'visible' => '1'],
                ['key' => 'e-c', 'name' => 'Corporate Events', 'description' => 'Company parties.', 'preset' => 'corporate', 'visible' => '1'],
            ]]),
            'pricing_strategy' => fn () => $this->continueStep('pricing_strategy', ['value' => 'fixed_packages']),
            'packages' => fn () => $this->continueStep('packages', ['items' => [
                ['name' => 'Essential', 'price' => '499', 'currency_code' => 'USD', 'description' => 'The basics.', 'features' => ['2 hours'], 'service_key' => 'k-open'],
                ['name' => 'Signature', 'price' => '799', 'currency_code' => 'USD', 'description' => 'Our most popular.', 'features' => ['3 hours', 'Props'], 'featured' => '1'],
            ]]),
            'addons' => fn () => $this->continueStep('addons', ['items' => [['key' => 'x-hours', 'name' => 'Extra hours', 'description' => 'More time.', 'price' => '75', 'currency_code' => 'USD', 'preset' => 'extra_hours']]]),
            'offers_backdrops' => fn () => $this->continueStep('offers_backdrops', ['value' => '1']),
            'backdrops' => fn () => $this->continueStep('backdrops', ['items' => [['key' => 'b1', 'name' => 'Floral wall', 'category' => 'backdrop', 'image_path' => $backdropPhoto, 'availability' => '1']]]),
            'gallery' => fn () => $this->continueStep('gallery', ['value' => '3']),
            'photo_rights' => fn () => $this->continueStep('photo_rights', ['value' => ['confirmed']]),
            'brand_personality' => fn () => $this->continueStep('brand_personality', ['value' => 'playful']),
            'about_story' => fn () => $this->continueStep('about_story', ['value' => $this->jget($this->url('content-default'), ['kind' => 'about', 'variant' => 0])->json('text')]),
            'advantages' => fn () => $this->continueStep('advantages', ['value' => ['digital_sharing', 'premium_equipment']]),
            'testimonials' => fn () => $this->continueStep('testimonials', ['items' => []]),
            'primary_cta' => fn () => $this->continueStep('primary_cta', ['value' => 'quote_request']),
            'contact_form_fields' => fn () => $this->continueStep('contact_form_fields', ['value' => ['name', 'phone', 'email', 'event_date', 'event_type', 'event_location', 'message']]),
            'faq_items' => fn () => $this->continueStep('faq_items', ['items' => $this->jget($this->url('content-default'), ['kind' => 'faq'])->json('items')]),
        ];

        foreach ($screens as $key => $send) {
            $override = array_key_exists($key, $o) ? $o[$key] : '__default__';

            if ($override === null) {
                continue;
            }

            if (is_callable($override)) {
                $send = $override;
            }

            $send()->assertOk();
        }

        return ['booth_photos' => ['k-open' => $boothPhoto, 'k-mirror' => $boothPhoto2], 'backdrop_photo' => $backdropPhoto];
    }
}
