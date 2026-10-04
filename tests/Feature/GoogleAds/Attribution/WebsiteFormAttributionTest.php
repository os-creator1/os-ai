<?php

namespace Tests\Feature\GoogleAds\Attribution;

use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsitePublisher;
use App\Models\Website;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\GoogleAds\Attribution\Concerns\BuildsAttributionCookies;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/** Surface 2 of 3: the public website form, plus the capture middleware on the real public pages. */
class WebsiteFormAttributionTest extends TestCase
{
    use BuildsAttributionCookies;
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private Website $website;

    private WebsiteForm $form;

    private WebsitePage $page;

    private $business;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->entitledTenant();
        $this->website = $this->createWebsite($this->business);
        $this->form = $this->website->forms()->create([
            'business_id' => $this->website->business_id,
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Photo Booth Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Request a quote',
        ]);
        $this->homePage($this->website);
        $this->page = $this->subPage($this->website, 'quote', ['sections' => [$this->section('form', ['form_uid' => $this->form->uid])]]);
        app(WebsitePublisher::class)->publish($this->website, $this->platformAdminId());
    }

    private function submit(array $cookies = [], array $data = [], array $headers = [])
    {
        return $this->withCookies($cookies)->withHeaders($headers)->post(
            route('public.website.form.submit', [$this->website->public_id, $this->form->uid, $this->page->uid]),
            $data + ['name' => 'Jamie Rivera', 'phone' => '5551234567']
        );
    }

    public function test_the_public_website_pages_set_the_cookies_for_a_tagged_arrival_only(): void
    {
        $home = $this->get(route('public.website.home', $this->website->public_id).'?gclid='.self::CLICK.'&utm_campaign=spring');
        $home->assertOk();
        $this->assertNotNull($home->getCookie('bos_at_first'));
        $this->assertSame('spring', json_decode($home->getCookie('bos_at_last')->getValue(), true)['utm_campaign']);

        $page = $this->get(route('public.website.page', [$this->website->public_id, 'quote']).'?utm_source=x');
        $this->assertNotNull($page->getCookie('bos_at_first'));

        $untagged = $this->get(route('public.website.home', $this->website->public_id));
        $this->assertNull($untagged->getCookie('bos_at_first'));

        $gpc = $this->withHeaders(['Sec-GPC' => '1'])->get(route('public.website.home', $this->website->public_id).'?gclid='.self::CLICK);
        $this->assertNull($gpc->getCookie('bos_at_first'));
        $this->assertNull($gpc->getCookie('bos_at_last'));
    }

    public function test_a_tagged_website_submission_records_attribution_for_the_websites_business(): void
    {
        $this->submit($this->touchCookies(['gclid' => self::CLICK, 'utm_source' => 'google']))->assertRedirect();

        $submission = WebsiteFormSubmission::query()->sole();
        $row = $this->touchRows()[0];

        $this->assertSame('website_form', $row->entry_surface);
        $this->assertSame('website_form_submission', $row->subject_type);
        $this->assertSame((int) $submission->id, (int) $row->subject_id);
        $this->assertSame((int) $this->business->id, (int) $row->business_id);
        $this->assertNull($row->business_location_id);
        $this->assertNotNull($submission->contact_id);
        $this->assertSame((int) $submission->contact_id, (int) $row->contact_id);
        $this->assertSame(self::CLICK, $row->gclid);
    }

    public function test_the_hostile_cookie_cannot_choose_the_business(): void
    {
        $this->submit(['bos_at_first' => $this->touchJson(['gclid' => self::CLICK, 'business_id' => 987654])])->assertRedirect();

        $this->assertSame((int) $this->business->id, (int) $this->touchRows()[0]->business_id);
    }

    public function test_spam_and_duplicate_submissions_record_nothing(): void
    {
        $cookies = $this->touchCookies(['gclid' => self::CLICK]);

        $this->submit($cookies, ['name' => 'Bot', 'phone' => '5550000000', 'website_hp' => 'gotcha'])->assertRedirect();
        $this->assertSame(1, WebsiteFormSubmission::query()->where('is_spam', true)->count());
        $this->assertCount(0, $this->touchRows());

        $this->submit($cookies)->assertRedirect();
        $this->assertCount(1, $this->touchRows());

        $this->submit($cookies)->assertRedirect(); // exact duplicate within the window: null result
        $this->assertSame(2, WebsiteFormSubmission::query()->count());
        $this->assertCount(1, $this->touchRows());
    }

    public function test_opt_out_signals_record_nothing(): void
    {
        $this->submit($this->touchCookies(['gclid' => self::CLICK]), [], ['Sec-GPC' => '1'])->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::query()->count());
        $this->assertCount(0, $this->touchRows());
    }
}
