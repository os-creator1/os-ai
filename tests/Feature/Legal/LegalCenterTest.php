<?php

namespace Tests\Feature\Legal;

use App\Http\Controllers\Public\LegalController;
use App\Library\Legal\LegalDocument;
use App\Library\Legal\LegalRegistry;
use App\Library\Legal\LegalRenderer;
use App\Library\Marketing\MarketingSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * The MotionGrove Legal Center: routes, release gate, content integrity, safe rendering. No database.
 */
class LegalCenterTest extends TestCase
{
    private const SLUGS = [
        'terms-of-service', 'privacy-policy', 'refund-policy', 'acceptable-use', 'data-processing-addendum',
        'cookie-policy', 'payments-and-esignatures', 'agency-terms', 'security',
    ];

    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketing.enabled' => true, 'marketing.hosts' => [], 'legal.preview_drafts' => false]);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '') {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    private function preview(): void
    {
        config(['legal.preview_drafts' => true]);
    }

    /** One clean, approved, sealed document in an isolated directory, with the company facts filled in. */
    private function publishFixture(string $slug = 'terms-of-service'): LegalDocument
    {
        $this->tmp = sys_get_temp_dir().'/legal-'.uniqid();
        File::makeDirectory($this->tmp);
        $source = "Operator: {{registered_entity}}\n\n## 1. Agreement\n\n1.1. Clean approved fixture text.\n\n## 2. Contact\n\n2.1. Write to {{legal_email}}.\n";
        File::put($this->tmp.'/'.$slug.'.md', $source);

        config([
            'legal.path' => $this->tmp,
            'legal.facts.registered_entity.value' => 'Fixture entity, code 000, Example street 1',
            'legal.facts.legal_email.value' => 'legal@example.test',
            'legal.documents.'.$slug.'.status' => 'approved',
            'legal.documents.'.$slug.'.approved_by' => 'Release owner',
            'legal.documents.'.$slug.'.approved_at' => '2026-11-01',
            'legal.documents.'.$slug.'.effective_date' => '2026-11-15',
        ]);
        File::put($this->tmp.'/hashes.json', json_encode([$slug.'@1.0-draft' => LegalDocument::hashOf($source)]));

        return app(LegalRegistry::class)->find($slug);
    }

    // ----------------------------------------------------------- release gate

    public function test_unapproved_documents_are_not_public_and_nothing_displaces_the_existing_pages(): void
    {
        foreach (array_diff(self::SLUGS, ['privacy-policy']) as $slug) {
            $this->get('/'.$slug)->assertNotFound();
        }
        $this->get('/legal')->assertNotFound();

        foreach ((new LegalRegistry(new LegalRenderer))->all() as $doc) {
            $this->assertFalse($doc->isPublished(), $doc->slug.' must not be published until approved');
            $this->assertNotSame([], $doc->blockers());
        }
    }

    public function test_a_document_needs_status_approval_record_facts_and_a_sealed_hash_to_publish(): void
    {
        $doc = $this->publishFixture();
        $this->assertTrue($doc->isPublished(), implode('; ', $doc->blockers()));

        foreach ([
            'legal.documents.terms-of-service.status' => 'draft',
            'legal.documents.terms-of-service.approved_by' => null,
            'legal.documents.terms-of-service.approved_at' => null,
            'legal.documents.terms-of-service.effective_date' => null,
            'legal.facts.legal_email.value' => '',
        ] as $key => $broken) {
            $good = config($key);
            config([$key => $broken]);
            $this->assertFalse(app(LegalRegistry::class)->find('terms-of-service')->isPublished(), "$key must block release");
            config([$key => $good]);
        }

        // Editing approved text without a new version breaks the seal.
        File::append($this->tmp.'/terms-of-service.md', "\n2.2. Silent edit.\n");
        $this->assertFalse(app(LegalRegistry::class)->find('terms-of-service')->isPublished());
    }

    public function test_a_published_document_renders_without_draft_markers_or_noindex(): void
    {
        $this->publishFixture();

        $response = $this->get('/terms-of-service');
        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringNotContainsString('Draft — pending legal approval', $html);
        $this->assertStringNotContainsString('[VERIFY', $html);
        $this->assertNull($response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('15 November 2026', $html);
        $this->assertStringContainsString('Fixture entity, code 000', $html);
        $this->assertStringContainsString('legal@example.test', $html);
        $this->assertStringContainsString('Jazmin Media MB', $html);
    }

    public function test_draft_preview_is_noindex_bannered_and_never_claims_approval(): void
    {
        $this->preview();

        $response = $this->get('/terms-of-service');
        $response->assertOk();
        $this->assertSame('noindex, nofollow', $response->headers->get('X-Robots-Tag'));
        $html = $response->getContent();
        $this->assertStringContainsString('Draft — pending legal approval', $html);
        $this->assertStringContainsString('name="robots" content="noindex, nofollow"', $html);
        $this->assertDoesNotMatchRegularExpression('/attorney[- ]approved|legally certified|guaranteed enforceable|legally approved/i', strip_tags($html));
    }

    // ----------------------------------------------------------------- routes

    public function test_every_required_legal_route_renders_with_toc_summary_print_and_one_h1(): void
    {
        $this->preview();

        foreach (array_diff(self::SLUGS, ['privacy-policy']) as $slug) {
            $html = $this->get('/'.$slug)->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<h1'), "$slug h1");
            $this->assertStringContainsString('data-lg-print', $html, "$slug print");
            $this->assertStringContainsString('aria-label="Contents"', $html, "$slug toc");
            $this->assertStringContainsString('Document summary', $html, "$slug summary");
            $this->assertStringContainsString('aria-label="Breadcrumb"', $html, "$slug breadcrumb");
            $this->assertStringContainsString('<link rel="canonical"', $html);
        }

        $this->get('/legal')->assertOk()->assertSee('Legal Center');
    }

    public function test_privacy_policy_keeps_its_single_existing_route_and_serves_the_legal_center_text_in_preview(): void
    {
        $this->preview();

        $this->get('/privacy-policy')->assertOk()->assertSee('Privacy Policy')->assertSee('Document summary');
    }

    public function test_the_legacy_terms_of_use_url_redirects_to_the_one_canonical_terms_page(): void
    {
        $this->preview();
        $this->get('/terms-of-use')->assertRedirect(url('/terms-of-service'));

        $this->publishFixture();
        config(['legal.preview_drafts' => false]);
        $this->get('/terms-of-use')->assertStatus(301)->assertRedirect(url('/terms-of-service'));
    }

    public function test_no_uri_is_registered_twice_and_there_is_exactly_one_terms_page(): void
    {
        $counts = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (in_array('GET', $route->methods(), true)) {
                $counts[$route->uri()] = ($counts[$route->uri()] ?? 0) + 1;
            }
        }
        foreach (['terms-of-service', 'privacy-policy', 'refund-policy', 'acceptable-use', 'data-processing-addendum', 'cookie-policy', 'payments-and-esignatures', 'agency-terms', 'security', 'legal', 'terms-of-use'] as $uri) {
            $this->assertSame(1, $counts[$uri] ?? 0, "$uri must be registered exactly once");
        }
        $this->assertFalse(Route::has('legal.privacy-policy'));
        $this->assertFalse(Route::has('legal.terms-of-use'));
    }

    public function test_custom_domains_do_not_serve_the_platform_legal_center(): void
    {
        $this->preview();
        config(['marketing.hosts' => ['getmotiongrove.test']]);

        // Resolved directly: an unknown host is answered by the application's custom-domain layer (database) before routing.
        $request = Request::create('http://agency-client.example/terms-of-service');
        $this->assertFalse(app(MarketingSite::class)->servesRequest($request));
        $this->expectException(NotFoundHttpException::class);
        app(LegalController::class)->show($request, 'terms-of-service');
    }

    // ------------------------------------------------------------------ footer

    public function test_the_footer_lists_legal_documents_only_when_they_are_live(): void
    {
        $this->get('/')->assertOk()->assertDontSee('Legal Center')->assertDontSee('Data Processing Addendum');

        $this->preview();
        $html = $this->get('/')->assertOk()->getContent();
        foreach (['Legal Center', 'Terms &amp; Conditions', 'Privacy Policy', 'Billing &amp; Refunds', 'Acceptable Use', 'Data Processing Addendum', 'Cookie Policy', 'Payments &amp; E-Signatures', 'Agency &amp; White-Label', 'Security'] as $label) {
            $this->assertStringContainsString($label, $html, "footer missing $label");
        }
        $this->assertStringContainsString('trading brand operated by Jazmin Media MB', $html);
        $this->assertStringContainsString(url('/terms-of-service'), $html);
    }

    // -------------------------------------------------------- content integrity

    public function test_every_document_has_a_source_file_complete_sections_and_stable_hashes(): void
    {
        $registry = app(LegalRegistry::class);

        $this->assertSame(self::SLUGS, array_keys(config('legal.documents')));
        $this->assertCount(6, array_filter(config('legal.documents'), fn ($d) => $d['primary']), 'six primary legal pages');

        foreach (self::SLUGS as $slug) {
            $doc = $registry->find($slug) ?? $this->fail("$slug has no source file");
            $rendered = $registry->render($doc);

            $this->assertGreaterThanOrEqual(5, count($rendered['sections']), "$slug sections");
            $this->assertSame($doc->hash, LegalDocument::hashOf(File::get(resource_path("legal/$slug.md"))), "$slug hash is stable");
            $this->assertSame($doc->hash, $registry->find($slug)->hash);
            $this->assertNotSame([], $doc->summary);
            $this->assertStringContainsString('Jazmin Media MB', $doc->source, "$slug names the contracting entity");
        }
    }

    public function test_approved_documents_match_their_sealed_hash(): void
    {
        $all = app(LegalRegistry::class)->all();
        $this->assertCount(count(self::SLUGS), $all);
        foreach ($all as $doc) {
            if ($doc->status === 'approved') {
                $this->assertTrue($doc->sealed, $doc->slug.' is approved but its text no longer matches the sealed hash: bump the version.');
            }
        }
    }

    public function test_the_important_provisions_are_preserved_verbatim(): void
    {
        $src = fn (string $slug) => File::get(resource_path("legal/$slug.md"));

        $terms = $src('terms-of-service');
        foreach ([
            'PROPOSED B2B CAP — LEGAL REVIEW REQUIRED', 'the greater of (a) fees paid to Company', 'USD $100',
            'not apply to matters that law prohibits limiting', 'indirect, special, incidental, punitive, exemplary or consequential damages',
            'qualified electronic signatures', 'does not become a personal guarantor', 'courts of competent jurisdiction in Vilnius',
            'law of the Republic of Lithuania', 'Stripe, Telnyx, Google, Meta, Microsoft, Zoom' === '' ? '' : 'Stripe, Telnyx, Google, Meta, Microsoft, Zoom' !== '' ? 'Stripe, Telnyx' : '',
            'not a calendar month', 'no guarantee',
        ] as $needle) {
            $this->assertStringContainsStringIgnoringCase($needle, $terms, $needle);
        }

        $refund = $src('refund-policy');
        foreach (['NON-REFUNDABLE', 'Core $97, Growth $297 and Agency $497', 'Only personnel expressly granted refund approval authority', 'Nothing in this Policy prohibits legitimate chargebacks', 'approximately 13 renewal charges in 364 days'] as $needle) {
            $this->assertStringContainsString($needle, $refund, $needle);
        }

        $aup = $src('acceptable-use');
        foreach (['TCPA', 'A2P 10DLC', 'STOP', 'CAN-SPAM', 'CASL', 'GDPR/ePrivacy', 'NO EMERGENCY SERVICES', 'do-not-call', 'BYO', 'One-party consent is not universally sufficient'] as $needle) {
            $this->assertStringContainsString($needle, $aup, $needle);
        }

        $payments = $src('payments-and-esignatures');
        foreach (['qualified electronic signature', 'These provider rights are not displaced by this Addendum', 'No bank debit, reserve seizure or connected-account charge may occur'] as $needle) {
            $this->assertStringContainsString($needle, $payments, $needle);
        }
    }

    public function test_no_document_misstates_the_legal_identity_or_exposes_private_offers(): void
    {
        foreach (self::SLUGS as $slug) {
            $text = File::get(resource_path("legal/$slug.md"));

            $this->assertDoesNotMatchRegularExpression('/MotionGrove,? (Inc|LLC|Ltd|UAB|Limited|Corp)\b/i', $text, "$slug invents a separate company");
            $this->assertDoesNotMatchRegularExpression('/attorney[- ]approved|legally certified|guaranteed enforceable|counsel[- ]approved/i', $text);
            $this->assertStringNotContainsString('$197', $text, "$slug exposes a private offer price");
            $this->assertDoesNotMatchRegularExpression('/28[- ]day (free )?trial/i', $text, "$slug exposes a private trial");
            $this->assertStringNotContainsString('INTERNAL APPENDIX', $text, "$slug leaks the internal checklist");
            $this->assertDoesNotMatchRegularExpression('/\[x\]|\[ \]/', $text, "$slug leaks checklist items");
        }

        $this->assertStringContainsString('trading brand', config('legal.entity.description'));
        $this->assertStringContainsString('not a separate legal entity', File::get(resource_path('legal/terms-of-service.md')));
    }

    public function test_unverified_company_details_are_never_invented(): void
    {
        foreach (config('legal.facts') as $key => $fact) {
            $this->assertNull($fact['value'], "$key must come from LEGAL_* configuration, never be hard-coded in the repository");
        }
    }
}
