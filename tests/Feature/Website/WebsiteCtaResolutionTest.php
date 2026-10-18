<?php

namespace Tests\Feature\Website;

use App\Library\Website\Design\WebsiteCtaResolver;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website V1 final — the one answer to "what does the main button do?":
 * booking, else the quote form, else a contact page, else a phone/email,
 * else NO button — and never a dead link. (The booking branch has its own
 * test beside the Calendar fixtures: WebsiteCtaBookingTest.)
 */
class WebsiteCtaResolutionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
    }

    private function resolver(): WebsiteCtaResolver
    {
        return app(WebsiteCtaResolver::class);
    }

    private function contactPage(bool $form): array
    {
        return ['uid' => 'c', 'slug' => 'photo-booth-contact', 'url' => 'https://example.test/contact', 'has_form' => $form];
    }

    public function test_the_quote_form_wins_over_a_plain_contact_page_over_a_phone_over_an_email_over_nothing(): void
    {
        [, $business] = $this->entitledTenant(['phone' => '3125550188', 'email' => 'hello@lumabooth.test']);

        $form = $this->resolver()->resolve($business, [$this->contactPage(true)]);
        $this->assertSame(['kind' => 'form', 'label' => 'Request a quote', 'url' => 'https://example.test/contact'], $form);

        $page = $this->resolver()->resolve($business, [$this->contactPage(false)]);
        $this->assertSame('contact_page', $page['kind']);
        $this->assertSame('Contact us', $page['label']);

        $phone = $this->resolver()->resolve($business, []);
        $this->assertSame(['kind' => 'phone', 'label' => 'Call us', 'url' => 'tel:3125550188'], $phone);

        DB::table('businesses')->where('id', $business->id)->update(['phone' => null]);
        $email = $this->resolver()->resolve($business->fresh(), []);
        $this->assertSame(['kind' => 'email', 'label' => 'Email us', 'url' => 'mailto:hello@lumabooth.test'], $email);

        DB::table('businesses')->where('id', $business->id)->update(['email' => null]);
        $this->assertNull($this->resolver()->resolve($business->fresh(), []), 'Nothing to point at means no button — never a dead one.');
    }

    public function test_a_phone_number_is_reduced_to_a_dialable_tel_link(): void
    {
        [, $business] = $this->entitledTenant(['phone' => '+1 (312) 555-0188']);

        $this->assertSame('tel:+13125550188', $this->resolver()->resolve($business, [])['url']);
    }

    public function test_a_section_button_that_goes_nowhere_falls_back_to_the_site_button(): void
    {
        $site = ['kind' => 'phone', 'label' => 'Call us', 'url' => 'tel:3125550188'];
        $resolver = $this->resolver();

        $this->assertSame(['label' => 'Check availability', 'url' => 'https://example.test/book'], $resolver->sectionCta(['label' => 'Check availability', 'url' => 'https://example.test/book'], $site));
        $this->assertSame(['label' => 'Call', 'url' => 'tel:5551234'], $resolver->sectionCta(['label' => 'Call', 'url' => 'tel:5551234'], $site));
        $this->assertSame(['label' => 'Call us', 'url' => 'tel:3125550188'], $resolver->sectionCta(['label' => 'Book', 'url' => '#'], $site), 'A "#" link is replaced.');
        $this->assertSame(['label' => 'Call us', 'url' => 'tel:3125550188'], $resolver->sectionCta(['label' => 'Book', 'url' => ''], $site));
        $this->assertSame(['label' => 'Call us', 'url' => 'tel:3125550188'], $resolver->sectionCta(null, $site));
        $this->assertSame(['label' => 'Call us', 'url' => 'tel:3125550188'], $resolver->sectionCta(['label' => 'x', 'url' => 'javascript:alert(1)'], $site), 'A script URL is never kept.');
        $this->assertNull($resolver->sectionCta(['label' => 'Book', 'url' => '#'], null), 'No site button either: no button.');
    }

    public function test_a_published_site_never_renders_a_dead_button(): void
    {
        [, $business] = $this->entitledTenant(['phone' => '3125550188']);
        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'));
        // A hero written without a button still gets the site's main button; a CTA band keeps its own (valid) address.
        $this->homePage($website, ['sections' => [
            $this->section('hero', ['heading' => 'Hello', 'primary_cta' => null, 'secondary_cta' => null]),
            ['type' => 'cta', 'data' => ['heading' => 'Ready?', 'body' => null, 'buttons' => [['label' => 'Email us', 'url' => 'mailto:hello@lumabooth.test']]]],
        ]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

        $this->assertStringNotContainsString('href="#"', $html);
        $this->assertStringContainsString('data-testid="header-cta"', $html);
        $this->assertSame(1, substr_count($html, 'href="tel:3125550188" data-testid="header-cta"'));
        $this->assertStringContainsString('wd-hero-ctas', $html, 'The hero shows the site button.');
        $this->assertStringContainsString('href="mailto:hello@lumabooth.test"', $html, 'The band keeps its own address.');
    }

    public function test_with_no_way_to_reach_the_business_no_button_is_rendered_at_all(): void
    {
        [, $business] = $this->entitledTenant();
        DB::table('businesses')->where('id', $business->id)->update(['phone' => null, 'email' => null]);
        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($business->fresh(), WebsiteTemplate::findActiveOrFail('photo_booth_modern'));
        $this->homePage($website, ['sections' => [
            $this->section('hero', ['heading' => 'Hello', 'primary_cta' => null]),
        ]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

        $this->assertStringNotContainsString('data-testid="header-cta"', $html);
        $this->assertStringNotContainsString('href="#"', $html);
        $this->assertStringNotContainsString('wd-hero-ctas', $html);
        $this->assertStringNotContainsString('wd-btn', $html, 'Not one button anywhere, rather than a dead one.');
    }

    public function test_the_header_button_points_at_the_quote_form_page_when_there_is_no_booking(): void
    {
        [, $business] = $this->entitledTenant(['phone' => '3125550188']);
        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($business, WebsiteTemplate::findActiveOrFail('photo_booth_luxury'));
        $form = \App\Models\WebsiteForm::create(['website_id' => $website->id, 'business_id' => $business->id, 'type' => \App\Models\WebsiteForm::TYPE_QUOTE_REQUEST, 'name' => 'Quote', 'fields' => \App\Library\Website\WebsiteFormPresets::photoBoothQuoteRequest(), 'submit_label' => 'Request a quote']);
        $this->homePage($website);
        $this->subPage($website, 'photo-booth-contact', ['title' => 'Contact', 'sections' => [$this->section('hero'), $this->section('form', ['form_uid' => $form->uid])]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

        $contactUrl = route('public.website.page', [$website->public_id, 'photo-booth-contact']);
        $this->assertStringContainsString('href="' . $contactUrl . '" data-testid="header-cta">Request a quote', $html);
    }
}
