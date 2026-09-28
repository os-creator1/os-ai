<?php

namespace Tests\Feature\Marketing;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\MarketingContentSettings;
use App\Models\MarketingFaq;
use App\Models\MarketingTestimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Public Marketing Homepage contract — the platform's own front door at
 * `/`, replacing the previous unconditional redirect to /login.
 */
class PublicHomepageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
        $this->ensureRequiredAppConfigRowsExist();
        $this->platformAdminId();
    }

    public function test_root_renders_the_marketing_homepage_instead_of_redirecting_to_login(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertViewIs('marketing.home');
        $response->assertSee('Create account', false);
        $response->assertSee(route('login'), false);
    }

    public function test_root_still_redirects_to_install_during_the_install_stage(): void
    {
        config(['app.stage' => 'new']);

        $response = $this->get('/');

        $response->assertRedirect('install');
    }

    public function test_homepage_shows_only_sellable_plans_from_the_v1_catalog(): void
    {
        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 14, price: '297.00');

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('297.00', false);
        $response->assertSee('14 day free trial', false);
    }

    public function test_homepage_shows_no_plans_message_when_nothing_is_sellable_yet(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Signups are not open right now', false);
    }

    public function test_homepage_shows_owner_edited_hero_copy(): void
    {
        MarketingContentSettings::query()->create([
            'hero_headline' => 'A distinctive owner-written headline',
            'hero_subheadline' => 'A distinctive owner-written subheadline',
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('A distinctive owner-written headline', false);
        $response->assertSee('A distinctive owner-written subheadline', false);
    }

    public function test_homepage_only_shows_visible_faqs(): void
    {
        MarketingFaq::query()->create(['question' => 'Visible question?', 'answer' => 'Visible answer.', 'is_visible' => true, 'position' => 1]);
        MarketingFaq::query()->create(['question' => 'Hidden question?', 'answer' => 'Hidden answer.', 'is_visible' => false, 'position' => 2]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Visible question?', false);
        $response->assertDontSee('Hidden question?', false);
    }

    public function test_homepage_only_shows_visible_testimonials_with_a_poster_image(): void
    {
        MarketingTestimonial::query()->create([
            'name' => 'Visible Person',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => 'images/marketing/testimonials/fake.png',
            'is_visible' => true,
            'position' => 1,
        ]);
        MarketingTestimonial::query()->create([
            'name' => 'Hidden Person',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => 'images/marketing/testimonials/fake2.png',
            'is_visible' => false,
            'position' => 2,
        ]);
        // Visible but incomplete (no poster yet) — must never render.
        MarketingTestimonial::query()->create([
            'name' => 'Incomplete Person',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image_path' => null,
            'is_visible' => true,
            'position' => 3,
        ]);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('Visible Person', false);
        $response->assertDontSee('Hidden Person', false);
        $response->assertDontSee('Incomplete Person', false);
    }

    public function test_homepage_hides_the_testimonials_section_entirely_when_none_are_ready(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertDontSee('Feedback from an earlier business', false);
    }
}
