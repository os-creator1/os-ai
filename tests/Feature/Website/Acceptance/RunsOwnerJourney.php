<?php

namespace Tests\Feature\Website\Acceptance;

use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Customer;
use App\Models\Website;
use App\Models\Workspace;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\Support\WebsiteAcceptance\AcceptanceFakeAi;
use Tests\Support\WebsiteAcceptance\PhotoBoothFixture;

/**
 * Drives the REAL customer flow for the fixture Photo Booth company — only
 * HTTP requests to the same routes the browser posts to, never a hand-written
 * Website row: Business data → setup template → gallery photos → one POST per
 * wizard screen → logo + hero through the Review screen's look form →
 * Generate. Requires CreatesWebsiteFixtures + DrivesSmartWizard.
 */
trait RunsOwnerJourney
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    protected AcceptanceFakeAi $fakeAi;

    protected string $publicRoot;

    /**
     * A disposable public root so uploads and derivatives never touch the
     * repository's real public/images; the build manifest is copied in
     * because the Review screen's layout reads it.
     */
    protected function useDisposablePublicRoot(): void
    {
        $this->publicRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'website-acceptance-' . uniqid();
        mkdir($this->publicRoot, 0755, true);

        if (is_file(base_path('public/mix-manifest.json'))) {
            copy(base_path('public/mix-manifest.json'), $this->publicRoot . DIRECTORY_SEPARATOR . 'mix-manifest.json');
        }

        $this->app->usePublicPath($this->publicRoot);
    }

    protected function removeDisposablePublicRoot(?string $directory = null): void
    {
        $directory ??= $this->publicRoot ?? null;

        if ($directory === null || ! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDisposablePublicRoot($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    /** @return array{0: Customer, 1: Business, 2: Workspace} */
    protected function fixtureTenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant([
            'name' => PhotoBoothFixture::NAME,
            'phone' => PhotoBoothFixture::PHONE_DIGITS,
            'email' => PhotoBoothFixture::EMAIL,
            'description' => PhotoBoothFixture::ABOUT,
            'timezone' => 'America/Chicago',
        ]);

        // Primary studio location (shown publicly), plus a second served area Business Location (Naperville).
        $primary = new BusinessLocation([
            'business_id' => $business->id, 'name' => 'Chicago Studio', 'address_line_1' => '1200 W Fulton Market',
            'city' => PhotoBoothFixture::CITY, 'region' => PhotoBoothFixture::REGION, 'postal_code' => '60607', 'country_code' => 'US',
            'public_address' => true, 'service_mode' => 'hybrid',
        ]);
        $primary->is_primary = true;
        $primary->save();

        BusinessLocation::create([
            'business_id' => $business->id, 'name' => 'Naperville Service Area', 'service_mode' => 'service_area',
            'city' => 'Naperville', 'region' => PhotoBoothFixture::REGION, 'country_code' => 'US',
            'service_area_cities' => ['Naperville', 'Aurora', 'Wheaton', 'Oak Brook'],
        ]);

        return [$customer, $business->fresh(), $workspace];
    }

    /** @return array<string, array> the wizard's screen payloads for the fixture business */
    protected function fixtureScreens(): array
    {
        return [
            'business_name' => ['s' => [
                'business_name' => ['value' => PhotoBoothFixture::NAME],
                'phone' => ['value' => PhotoBoothFixture::PHONE_DIGITS],
                'email' => ['value' => PhotoBoothFixture::EMAIL],
                'primary_cta' => ['value' => 'quote_request'],
            ]],
            'service_area_cities' => ['value' => PhotoBoothFixture::AREAS],
            'booth_types' => ['s' => [
                'booth_types' => ['items' => PhotoBoothFixture::booths()],
                'services_event_types' => ['items' => PhotoBoothFixture::eventTypes()],
            ]],
            'packages' => ['items' => PhotoBoothFixture::packages()],
            'backdrops' => ['s' => ['backdrops' => ['items' => []], 'gallery' => ['value' => '0']]],
            'testimonials' => ['items' => PhotoBoothFixture::testimonials()],
            'about_story' => ['s' => [
                'about_story' => ['value' => PhotoBoothFixture::ABOUT],
                'brand_personality' => ['value' => 'elegant'],
            ]],
            'faq_items' => ['s' => ['faq_items' => ['items' => PhotoBoothFixture::faqs()], 'custom_section' => ['items' => []]]],
            'contact_form_fields' => ['value' => ['name', 'phone', 'email', 'event_date', 'event_type', 'message']],
        ];
    }

    /**
     * The complete owner journey up to and including Generate. Returns the
     * generated Website.
     *
     * @return array{0: Customer, 1: Business, 2: Workspace, 3: Website}
     */
    protected function runOwnerJourneyThroughGenerate(string $templateKey = 'photo_booth_modern', int $galleryPhotos = 8): array
    {
        [$customer, $business, $workspace] = $this->fixtureTenant();
        $this->authenticateAsCustomer($customer);
        $this->fakeAi = AcceptanceFakeAi::bind($this->app);

        // Choose the template (creates the Website shell), then upload the gallery like the Media screen does.
        $this->post($this->wizardUrl($workspace, $business, 'setup.template'), ['template_key' => $templateKey])->assertRedirect();
        $photos = [];
        for ($i = 1; $i <= $galleryPhotos; $i++) {
            $photos[] = PhotoBoothFixture::jpeg(1600, 1067, 'event-photo-' . $i, 100 + $i);
        }
        $this->postJson($this->wizardUrl($workspace, $business, 'setup.gallery.upload'), ['photos' => $photos])->assertOk()->assertJsonPath('status', 'success');

        foreach ($this->fixtureScreens() as $screenKey => $payload) {
            $this->postScreen($workspace, $business, $screenKey, $payload)->assertSessionHasNoErrors();
        }

        // Review: logo + a real large hero through the look form (the same POST the file picker submits).
        $this->post($this->wizardUrl($workspace, $business, 'look.update'), [
            'template_key' => $templateKey,
            'logo' => PhotoBoothFixture::logo(),
            'logo_alt' => PhotoBoothFixture::NAME . ' logo',
            'hero' => PhotoBoothFixture::jpeg(3600, 2250, 'hero', 7),
            'hero_alt' => 'Guests posing together at a wedding reception photo booth',
        ])->assertSessionHasNoErrors();

        $this->get($this->wizardUrl($workspace, $business, 'setup.review'))->assertOk();
        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))->assertRedirect($this->wizardUrl($workspace, $business, 'preview'));

        return [$customer, $business, $workspace, Website::where('business_id', $business->id)->sole()];
    }
}
