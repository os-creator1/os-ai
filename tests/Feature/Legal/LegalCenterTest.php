<?php

namespace Tests\Feature\Legal;

use App\Library\Legal\LegalDocuments;
use App\Models\LegalCompanyProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The public Legal Center: nine documents on the marketing host, from one text source and one company source. */
class LegalCenterTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = [
        'terms-of-service', 'privacy-policy', 'refund-policy', 'acceptable-use', 'data-processing-addendum',
        'cookie-policy', 'payments-and-esignatures', 'agency-terms', 'security',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketing.enabled' => true, 'marketing.hosts' => [], 'marketing.cta.book_demo_url' => null]);
    }

    public function test_registry_holds_exactly_the_nine_canonical_documents(): void
    {
        $docs = app(LegalDocuments::class);

        $this->assertSame(self::SLUGS, array_keys($docs->all()));
        $this->assertCount(6, $docs->tier('primary'));
        $this->assertSame(['payments-and-esignatures', 'agency-terms', 'security'], array_keys($docs->tier('supporting')));
        foreach ($docs->all() as $slug => $d) {
            $this->assertSame(url($slug), $d['url']);
            $this->assertNotSame('', $d['seo_title']);
            $this->assertNotSame('', $d['description']);
            $this->assertNotSame('', $d['version']);
        }
    }

    public function test_every_document_renders_complete_without_drafting_markers(): void
    {
        $docs = app(LegalDocuments::class);

        foreach (self::SLUGS as $slug) {
            $rendered = $docs->render($slug);
            $this->assertGreaterThanOrEqual(8, count($rendered['toc']), $slug.' has too few sections');

            $html = $this->get('/'.$slug)->assertOk()->getContent();

            $this->assertStringContainsString('<h1 class="lg-title">'.e($rendered['meta']['title']).'</h1>', $html, $slug);
            $this->assertStringContainsString('<link rel="canonical" href="'.url($slug).'">', $html, $slug);
            foreach ([$rendered['toc'][0], end($rendered['toc'])] as $section) {
                $this->assertStringContainsString('id="'.$section['id'].'"', $html, $slug);
            }
            foreach (['[VERIFY', '{{', 'SET TO ACTUAL', 'RELEASE HOLD', 'confirm against'] as $marker) {
                $this->assertStringNotContainsString($marker, $html, $slug.' leaks "'.$marker.'"');
            }
            $this->assertStringContainsString('Jazmin medija, MB', $html, $slug);
            $this->assertStringContainsString('306304073', $html, $slug);
            $this->assertStringContainsString('Bičių g. 23, LT-11111 Vilnius, Lithuania', $html, $slug);
            $this->assertStringContainsString('legal@getmotiongrove.com', $html, $slug);
        }
    }

    public function test_specialised_protections_are_present_in_full(): void
    {
        $terms = $this->get('/terms-of-service')->assertOk()->getContent();
        foreach (['Stripe', 'chargeback', 'refund', 'SMS', 'electronic signature', 'Agency', 'AI'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $terms, $needle);
        }
        $payments = $this->get('/payments-and-esignatures')->assertOk()->getContent();
        foreach (['Negative balances', 'chargeback', 'connected account', 'Stripe'] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $payments, $needle);
        }
        $refund = $this->get('/refund-policy')->assertOk()->getContent();
        $this->assertStringContainsString('Core $97, Growth $297 and Agency $497 per 28-day', $refund);
        $this->assertStringNotContainsStringIgnoringCase('per month', $refund);
    }

    public function test_contents_anchors_are_valid_unique_and_sections_are_never_empty(): void
    {
        foreach (self::SLUGS as $slug) {
            $html = $this->get('/'.$slug)->assertOk()->getContent();
            $toc = app(LegalDocuments::class)->render($slug)['toc'];

            $ids = array_column($toc, 'id');
            $this->assertSame($ids, array_values(array_unique($ids)), $slug.' has duplicate section ids');
            $this->assertSame(count($toc), count(array_unique(array_column($toc, 'label'))), $slug.' has duplicated sections');
            foreach ($ids as $id) {
                $this->assertSame(1, substr_count($html, 'id="'.$id.'"'), $slug.' #'.$id);
                $this->assertStringContainsString('href="#'.$id.'"', $html, $slug.' #'.$id);
            }

            preg_match_all('#<h2 id="[^"]+" class="lg-h2">.*?</h2>(.*?)(?=<h2 |<section class="lg-company")#s', $html, $m);
            foreach ($m[1] as $body) {
                $this->assertNotSame('', trim(strip_tags($body)), $slug.' has an empty section');
            }
        }
    }

    public function test_company_values_are_escaped_and_never_become_markup(): void
    {
        LegalCompanyProfile::query()->create([
            'revision' => 2, 'brand_name' => 'MotionGrove', 'legal_entity' => '<b>Evil</b> & Co',
            'company_number' => '1', 'registered_address' => "<script>alert(1)</script>\n\"Quoted\" **bold** St",
            'country' => 'Lithuania', 'legal_email' => 'legal@getmotiongrove.com',
        ]);

        $html = $this->get('/terms-of-service')->assertOk()->getContent();

        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringNotContainsString('<b>Evil</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;, &quot;Quoted&quot; **bold** St', $html);
    }

    public function test_legacy_terms_url_redirects_to_the_canonical_terms_on_the_marketing_host(): void
    {
        $this->get('/terms-of-use')->assertStatus(301)->assertRedirect(url('terms-of-service'));
    }

    public function test_privacy_policy_url_is_the_legal_center_on_the_marketing_host_with_a_single_route(): void
    {
        $this->get('/privacy-policy')->assertOk()->assertSee('Privacy Policy')->assertSee('About this document');

        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes());
        $this->assertCount(1, $routes->filter(fn ($r) => $r->uri() === 'privacy-policy'));
        $this->assertCount(1, $routes->filter(fn ($r) => $r->uri() === 'terms-of-use'));
    }

    public function test_other_hosts_never_serve_motiongrove_legal_documents(): void
    {
        config(['marketing.hosts' => ['www.motiongrove.test']]);

        foreach (['terms-of-service', 'refund-policy', 'security', 'legal'] as $path) {
            $this->get('http://customer-domain.test/'.$path)->assertNotFound();
        }
        $this->get('http://customer-domain.test/privacy-policy')->assertDontSee('About this document');
        $this->get('http://www.motiongrove.test/terms-of-service')->assertOk();
    }

    public function test_hub_and_footer_expose_all_legal_documents(): void
    {
        $hub = $this->get('/legal')->assertOk()->getContent();
        $fullFooter = $this->get('/')->assertOk()->getContent();
        $compactFooter = $this->get('/product')->assertOk()->getContent();

        foreach (self::SLUGS as $slug) {
            $this->assertStringContainsString('href="'.url($slug).'"', $hub, $slug);
            $this->assertStringContainsString('href="'.url($slug).'"', $fullFooter, $slug);
        }
        $this->assertStringContainsString('href="'.url('legal').'"', $compactFooter);
        foreach (array_keys(app(LegalDocuments::class)->tier('primary')) as $slug) {
            $this->assertStringContainsString('href="'.url($slug).'"', $compactFooter, $slug);
        }
    }

    public function test_page_has_print_action_mobile_contents_and_print_styles(): void
    {
        $html = $this->get('/terms-of-service')->assertOk()->getContent();

        $this->assertStringContainsString('Print / Save as PDF', $html);
        $this->assertStringNotContainsString('Download PDF', $html);
        $this->assertStringContainsString('class="lg-toc-m"', $html);
        $this->assertStringContainsString('@media print', (string) file_get_contents(public_path('marketing/css/marketing.css')));
    }

    public function test_unknown_legal_slug_and_existing_marketing_routes_are_unaffected(): void
    {
        foreach (['/', '/product', '/solutions', '/how-it-works', '/customers', '/pricing'] as $path) {
            $this->get($path)->assertOk();
        }
        $this->get('/not-a-legal-page')->assertNotFound();
    }
}
