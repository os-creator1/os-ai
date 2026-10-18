<?php

namespace Tests\Feature\Website;

use App\Models\BusinessLocation;
use App\Models\BusinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Website V1 final — "never ask twice": a question whose answer already lives
 * in the Business OS is shown filled in (display only; an answer the owner has
 * given always wins, and nothing is saved until they submit the screen).
 */
class WebsiteWizardPrefillTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    public function test_the_basics_screen_is_filled_from_the_business_the_owner_already_created(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(['name' => 'Luma Photo Booth Co', 'phone' => '3125550188', 'email' => 'hello@lumabooth.test', 'description' => 'Photo booths across Chicago.']);
        $this->authenticateAsCustomer($customer);
        $this->startV2Setup($workspace, $business);

        $page = $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['business_name']))->assertOk();

        $page->assertSee('value="Luma Photo Booth Co"', false);
        $page->assertSee('value="3125550188"', false);
        $page->assertSee('value="hello@lumabooth.test"', false);

        $this->assertSame([], $this->activeResponse($business)->answers ?? [], 'Showing a default saves nothing.');
    }

    public function test_the_about_screen_is_filled_with_the_existing_description(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(['description' => 'Photo booths across Chicago.']);
        $this->authenticateAsCustomer($customer);
        $this->startV2Setup($workspace, $business);

        $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['about_story']))->assertSee('Photo booths across Chicago.');
    }

    public function test_service_areas_already_saved_on_the_primary_location_are_offered_one_per_row(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $primary = $business->primaryLocation()->first() ?? BusinessLocation::forceCreate(['business_id' => $business->id, 'name' => 'HQ', 'country_code' => 'US', 'service_mode' => 'hybrid', 'is_primary' => true]);
        $primary->forceFill(['service_area_cities' => ['Chicago', 'Evanston', 'Oak Park']])->save();
        $this->authenticateAsCustomer($customer);
        $this->startV2Setup($workspace, $business);

        $page = $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['service_area_cities']))->assertOk();

        foreach (['Chicago', 'Evanston', 'Oak Park'] as $area) {
            $page->assertSee('value="' . $area . '"', false);
        }
    }

    public function test_an_answer_the_owner_already_gave_always_wins_over_the_default(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(['name' => 'Old Name']);
        $this->authenticateAsCustomer($customer);
        $this->startV2Setup($workspace, $business);
        $this->postScreen($workspace, $business, 'business_name', ['s' => [
            'business_name' => ['value' => 'Luma Photo Booth Co'],
            'phone' => ['value' => '3125550188'],
            'email' => ['value' => 'hello@lumabooth.test'],
            'primary_cta' => ['value' => 'quote_request'],
        ]]);

        $page = $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['business_name']))->assertOk();

        $page->assertSee('value="Luma Photo Booth Co"', false);
        $page->assertDontSee('value="Old Name"', false);
    }

    public function test_services_are_not_echoed_back_because_that_would_save_them_twice(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        BusinessService::create(['business_id' => $business->id, 'name' => 'Existing Booth', 'slug' => 'existing-booth', 'status' => 'active', 'sort_order' => 0]);
        $this->authenticateAsCustomer($customer);
        $this->startV2Setup($workspace, $business);

        $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['booth_types']))->assertOk()->assertDontSee('Existing Booth');
    }
}
