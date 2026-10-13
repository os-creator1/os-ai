<?php

namespace Tests\Feature\Website;

use App\Jobs\ExternalSite\CrawlExternalSite;
use App\Models\Business;
use App\Models\Website;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The Website starting screen: exactly two honest choices (build with
 * MotionGrove / use my existing website), no skip action, real destinations.
 */
class WebsiteChoiceScreenTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['external_site_audit.driver' => 'fake', 'external_site_audit.request_delay_ms' => 0]);
    }

    private function url(string $name, Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.website.' . $name, [$workspace->uid, $business->uid]);
    }

    public function test_a_business_with_no_website_sees_exactly_two_choices_and_no_skip_action(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $html = $this->get($this->url('show', $workspace, $business))->assertOk()->getContent();

        $this->assertSame(0, Website::where('business_id', $business->id)->count());
        $this->assertStringContainsString('Your website', $html);
        $this->assertStringContainsString('data-role="website-choices"', $html);
        $this->assertSame(2, substr_count($html, 'data-choice='));
        $this->assertStringContainsString('data-choice="hosted"', $html);
        $this->assertStringContainsString('data-choice="external"', $html);

        // The obsolete action is gone from the markup entirely.
        $this->assertStringNotContainsString('Do this later', $html);
        $this->assertStringNotContainsString('choose-later', $html);
        $this->assertStringNotContainsString('data-choice="later"', $html);
        $this->assertStringNotContainsString('name="mode" value="none"', $html);

        // The two surviving choices keep their copy, buttons and fields.
        foreach (['Build with MotionGrove', 'Use my existing website', 'Pages filled in from your business details', 'Edit and publish it here', 'Site audit of your pages', 'Ongoing monitoring and suggestions', 'Your website address'] as $text) {
            $this->assertStringContainsString($text, $html, $text);
        }
        $this->assertStringContainsString('data-role="choose-hosted"', $html);
        $this->assertStringContainsString('data-role="choose-external"', $html);
        $this->assertStringContainsString('name="website_url"', $html);
        $this->assertSame(2, substr_count($html, 'action="' . $this->url('mode.choose', $workspace, $business) . '"'));
    }

    public function test_the_layout_is_two_columns_on_desktop_and_stacks_on_mobile(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $html = $this->get($this->url('show', $workspace, $business))->assertOk()->getContent();

        $this->assertSame(2, substr_count($html, 'class="col-md-6 d-flex"'), 'two half-width columns from tablet up, stacked below');
        $this->assertStringNotContainsString('col-md-4', $html);
        $this->assertStringContainsString('@media (max-width:575.98px){.website-choices .choice-url-row .btn{width:100%}}', $html);
        $this->assertStringContainsString('btn btn-primary w-100', $html);
    }

    public function test_build_with_motiongrove_goes_to_the_real_guided_setup(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'hosted'])
            ->assertRedirect($this->url('setup.start', $workspace, $business));
        $this->assertSame('hosted', $business->fresh()->website_mode);
    }

    public function test_use_my_existing_website_connects_it_and_starts_the_audit(): void
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => 'studio-fixture.example'])
            ->assertRedirect($this->url('external.overview', $workspace, $business));

        $this->assertSame('external', $business->fresh()->website_mode);
        Queue::assertPushed(CrawlExternalSite::class, 1);

        // And that choice then leads straight to the external overview, not back here.
        $this->get($this->url('show', $workspace, $business))->assertRedirect($this->url('external.overview', $workspace, $business));
    }

    public function test_a_business_that_already_chose_to_build_gets_the_original_empty_state_not_this_screen(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => 'hosted']);

        $this->get($this->url('show', $workspace, $business))->assertOk()
            ->assertSee('Create my website')
            ->assertDontSee('data-role="website-choices"', false);
    }

    public function test_a_business_that_chose_later_before_the_action_was_removed_is_still_served_without_a_skip_button(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => 'none']);

        $this->get($this->url('show', $workspace, $business))->assertOk()
            ->assertSee('data-role="chose-later"', false)
            ->assertSee('Build my website')->assertSee('Connect existing website')
            ->assertDontSee('Do this later');
    }

    public function test_a_customer_without_the_website_capability_cannot_open_the_screen(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer, []);

        $this->get($this->url('show', $workspace, $business))->assertUnauthorized();
        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'hosted'])->assertUnauthorized();
        $this->assertNull($business->fresh()->website_mode);
    }
}
