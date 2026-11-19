<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsitePublisher;
use App\Models\Business;
use App\Models\Website;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The inquiry panel's phone follows the published snapshot's contract exactly like `contact_details`: a published page shows the phone
 * frozen at publish (even when that was "no phone"), only the owner's Preview reads the live business, and Show phone is respected everywhere.
 */
class InquiryPanelPhoneSnapshotTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private const FIRST = '(601) 555-0142';

    private const SECOND = '(773) 555-0188';

    private Business $business;

    private Website $website;

    private $customer;

    private $workspace;

    private function site(?string $phone, bool $showPhone = true): void
    {
        [$this->customer, $business, $this->workspace] = $this->entitledTenant();
        DB::table('businesses')->where('id', $business->id)->update(['phone' => $phone]);
        $this->business = $business->fresh();
        $this->website = $this->createWebsite($this->business);

        WebsitePage::create(['website_id' => $this->website->id, 'title' => 'Home', 'slug' => null, 'is_home' => true, 'sort_order' => 0, 'noindex' => false, 'seo_title' => null, 'meta_description' => null, 'sections' => [
            $this->section('hero'),
            $this->section('text', ['heading' => 'About the booth', 'body' => 'A booth for your event.']),
            ['type' => 'inquiry_panel', 'data' => ['heading' => 'Ask about the booth', 'body' => null, 'cta' => ['label' => 'Request a quote', 'url' => '/contact'], 'show_phone' => $showPhone, 'facts' => []]],
            ['type' => 'contact_details', 'data' => ['show_phone' => $showPhone, 'show_email' => false, 'show_address' => false]],
        ]]);
        WebsitePage::create(['website_id' => $this->website->id, 'title' => 'Contact', 'slug' => 'contact', 'is_home' => false, 'sort_order' => 1, 'noindex' => false, 'seo_title' => null, 'meta_description' => null, 'sections' => [$this->section('hero')]]);
    }

    private function publish(): void
    {
        app(WebsitePublisher::class)->publish($this->website->fresh(), $this->platformAdminId());
    }

    private function setBusinessPhone(?string $phone): void
    {
        DB::table('businesses')->where('id', $this->business->id)->update(['phone' => $phone]);
        $this->business = $this->business->fresh();
    }

    /** @return array{panel: string, contact: string} the visible phone text of the panel and of the contact_details block of the published home page */
    private function published(): array
    {
        return $this->phones($this->get(route('public.website.home', $this->website->fresh()->public_id))->assertOk()->getContent());
    }

    private function preview(): array
    {
        $this->authenticateAsCustomer($this->customer);
        $page = WebsitePage::where('website_id', $this->website->id)->where('is_home', true)->firstOrFail();

        return $this->phones($this->get(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $this->business->uid, $page->uid]))->assertOk()->getContent());
    }

    private function phones(string $html): array
    {
        preg_match('#<aside\b[^>]*data-testid="inquiry-panel".*?</aside>#s', $html, $panel);
        preg_match('#<ul class="website-contact-list">.*?</ul>#s', $html, $contact);

        return [
            'panel' => trim(preg_replace('/\s+/', ' ', strip_tags(($panel[0] ?? '') . ' ' . implode(' ', $this->telHrefs($panel[0] ?? ''))))),
            'contact' => trim(preg_replace('/\s+/', ' ', strip_tags(($contact[0] ?? '') . ' ' . implode(' ', $this->telHrefs($contact[0] ?? ''))))),
        ];
    }

    /** @return array<int, string> */
    private function telHrefs(string $html): array
    {
        preg_match_all('#href="(tel:[^"]+)"#', $html, $m);

        return $m[1];
    }

    public function test_a_published_page_keeps_the_phone_it_was_published_with_when_the_business_phone_changes_later(): void
    {
        $this->site('6015550142');
        $this->publish();
        $this->setBusinessPhone('7735550188');

        $shown = $this->published();

        $this->assertStringContainsString(self::FIRST, $shown['panel']);
        $this->assertStringContainsString('tel:6015550142', $shown['panel']);
        $this->assertStringNotContainsString(self::SECOND, $shown['panel']);
        $this->assertStringNotContainsString('7735550188', $shown['panel']);
        $this->assertSame(str_contains($shown['contact'], self::FIRST), true, 'contact_details behaves the same: frozen');
    }

    public function test_a_page_published_without_a_phone_never_picks_one_up_from_the_live_business_afterwards(): void
    {
        $this->site(null);
        $this->publish();
        $this->setBusinessPhone('7735550188');

        $shown = $this->published();

        $this->assertStringNotContainsString('555', $shown['panel'], 'the frozen "no phone" is final for the panel');
        $this->assertStringNotContainsString('tel:', $shown['panel']);
        $this->assertStringNotContainsString('555', $shown['contact'], 'and contact_details already behaved exactly this way');
    }

    public function test_republishing_freezes_the_new_phone(): void
    {
        $this->site('6015550142');
        $this->publish();
        $this->setBusinessPhone('7735550188');
        $this->assertStringContainsString(self::FIRST, $this->published()['panel']);

        $this->travel(5)->seconds();
        app('cache')->flush();
        $this->publish();

        $shown = $this->published();
        $this->assertStringContainsString(self::SECOND, $shown['panel']);
        $this->assertStringContainsString('tel:7735550188', $shown['panel']);
        $this->assertStringNotContainsString(self::FIRST, $shown['panel']);
        $this->assertStringContainsString(self::SECOND, $shown['contact']);
    }

    public function test_preview_reads_the_current_business_phone_without_any_publication(): void
    {
        $this->site(null);

        $this->assertStringNotContainsString('555', $this->preview()['panel'], 'no phone yet');

        $this->setBusinessPhone('7735550188');
        $this->assertStringContainsString(self::SECOND, $this->preview()['panel']);
        $this->assertStringContainsString('tel:7735550188', $this->preview()['panel']);

        $this->publish();
        $this->setBusinessPhone('6015550142');
        $this->assertStringContainsString(self::FIRST, $this->preview()['panel'], 'Preview shows what the NEXT publish would freeze');
        $this->assertStringContainsString(self::SECOND, $this->published()['panel'], 'while the published page still shows the frozen one');
    }

    public function test_show_phone_off_hides_the_phone_in_preview_and_on_the_published_page_even_when_the_business_has_one(): void
    {
        $this->site('6015550142', showPhone: false);

        $this->assertStringNotContainsString('555', $this->preview()['panel']);

        $this->publish();
        $this->setBusinessPhone('7735550188');

        $this->assertStringNotContainsString('555', $this->published()['panel']);
        $this->assertStringNotContainsString('tel:', $this->published()['panel']);
        $this->assertStringNotContainsString('555', $this->published()['contact']);
    }
}
