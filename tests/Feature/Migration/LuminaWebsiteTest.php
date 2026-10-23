<?php

namespace Tests\Feature\Migration;

use App\Library\Migration\Ghl\GhlImportPlanner;
use App\Library\Migration\Ghl\GhlImportWriter;
use App\Library\Migration\Lumina\LuminaAuraProvisioner;
use App\Library\Migration\Lumina\LuminaWebsiteBuilder;
use App\Library\Forms\FormOperationToken;
use App\Library\Forms\FormSubmissionService;
use App\Models\FormDeployment;
use App\Models\FormSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Real builder, real published preview, real public form route. Synthetic images; customer text is the real extracted copy. */
class LuminaWebsiteTest extends TestCase
{
    use RefreshDatabase;

    private string $assets;

    private string $manifest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assets = sys_get_temp_dir().'/lumina-assets-'.uniqid();
        mkdir($this->assets);
        $real = json_decode(file_get_contents(base_path('docs/migrations/lumina-aura/website-manifest.json')), true);
        $i = 0;
        foreach ($real['assets'] as $role => &$entry) {
            $im = imagecreatetruecolor(640 + $i, 480);
            imagefill($im, 0, 0, imagecolorallocate($im, ($i * 37) % 255, ($i * 71) % 255, ($i * 113) % 255));
            $path = "{$this->assets}/{$role}.jpg";
            imagejpeg($im, $path);
            $entry['sha256'] = hash_file('sha256', $path);
            $i++;
        }
        $this->manifest = "{$this->assets}/manifest.json";
        file_put_contents($this->manifest, json_encode($real));
    }

    private function site(): array
    {
        User::create(['first_name' => 'P', 'last_name' => 'A', 'email' => 'pa'.uniqid().'@example.test', 'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin']);
        $ctx = app(LuminaAuraProvisioner::class)->provision('owner@example.test', 'a-long-test-password-1', [['first' => 'Own', 'last' => 'Er', 'email' => 'owner@example.test']])['context'];
        // Creates the Event date / Event type fields the form maps onto (no records).
        app(GhlImportWriter::class)->import((new GhlImportPlanner())->plan(['location' => ['id' => 'L'], 'contacts' => []]), $ctx->business, $ctx->location, '+17205028306', false);

        $result = app(LuminaWebsiteBuilder::class)->build($ctx->business, $ctx->location, (int) $ctx->user->id, $this->assets, $this->manifest, base_path('docs/migrations/lumina-aura/source'));

        return [$ctx, $result];
    }

    public function test_site_is_published_complete_priceless_and_uses_the_source_phone(): void
    {
        [$ctx, $result] = $this->site();
        $site = $result['website'];

        $this->assertSame([], $result['missing']);
        $this->assertSame('published', (string) ($site->status->value ?? $site->status));
        $this->assertEqualsCanonicalizing(
            ['', '360-video-booth', 'backdrops', 'gallery', 'faq', 'check-availability', 'privacy-policy', 'terms-of-service', 'terms-and-conditions'],
            $site->pages->map(fn ($p) => (string) $p->slug)->all(),
        );

        $all = json_encode($site->pages->pluck('sections'));
        $this->assertDoesNotMatchRegularExpression('/\$\s?\d/', json_encode($site->pages->map(fn ($p) => $p->sections)->take(6)), 'No prices on marketing pages: the source pricing conflicts.');
        $this->assertStringNotContainsString('testimonials', $all, 'No testimonials exist in the source, so none are shown.');
        $this->assertStringNotContainsString('917-8741', $all);

        foreach ($site->pages as $page) {
            $path = $page->slug === null ? '' : '/'.$page->slug;
            $html = $this->get("/sites/{$site->public_id}{$path}")->assertOk()->getContent();
            $this->assertStringContainsString('(720) 502-8306', $html, "Phone on {$path}");
            $this->assertStringNotContainsString('917-8741', $html);
            $this->assertStringNotContainsString('leadconnectorhq', $html, 'No GHL hot-links.');
            $this->assertStringNotContainsString('filesafe.space', $html, 'No GHL hot-links.');
            $this->assertMatchesRegularExpression('/<title>[^<]+<\/title>/', $html);
        }

        $home = $this->get("/sites/{$site->public_id}")->getContent();
        $this->assertStringContainsString('Lights, Camera,', $home);
        $this->assertStringContainsString('Memories!', $home);
        $this->assertStringContainsStringIgnoringCase('#9c6247', $home, 'Brand colour from the customer logo is applied.');
        $this->assertStringContainsString('/check-availability', $home);
        $this->assertSame(0, DB::table('website_domains')->count(), 'Platform preview path only: no custom domain.');
    }

    public function test_the_public_quote_form_creates_the_contact_opportunity_and_sends_nothing(): void
    {
        Mail::fake();
        [$ctx, $result] = $this->site();
        $deployment = FormDeployment::query()->where('uid', $result['report']['form_deployment_uid'])->firstOrFail();
        $version = $deployment->form->currentVersion();

        $keys = [];
        foreach ($version->fields as $field) {
            $keys[$field['type']] = $field['key'];
            $keys[$field['label']] = $field['key'];
        }

        $answers = [
            $keys['text'] => 'Test Lead',
            $keys['phone'] => '(303) 555-0142',
            $keys['email'] => 'Lead@Example.test',
            $keys['date'] => '2027-06-12',
            $keys['multi_select'] => ['Wedding', 'Party'],
            $keys['consent_transactional'] => '1',
            FormSubmissionService::TOKEN_FIELD => FormOperationToken::issue($deployment),
        ];

        $this->post(route('public.forms.submit', [$deployment->uid]), $answers)->assertRedirect();

        $submission = FormSubmission::query()->sole();
        $this->assertNotNull($submission->contact_id);
        $contact = DB::table('contacts')->where('id', $submission->contact_id)->first();
        $this->assertSame((int) $ctx->business->id, (int) $contact->business_id);
        $this->assertSame('13035550142', $contact->phone);

        $opp = DB::table('crm_opportunities')->where('contact_id', $contact->id)->sole();
        $this->assertSame((int) $ctx->business->id, (int) $opp->business_id);
        $this->assertSame('form', $opp->source);
        $pipeline = DB::table('crm_pipelines')->where('id', $opp->pipeline_id)->value('name');
        $this->assertSame('Inquiries', $pipeline);
        $this->assertSame('New Lead', DB::table('crm_pipeline_stages')->where('id', $opp->stage_id)->value('name'));

        // The customer's two consents are recorded as separate answers; only the one ticked is true.
        $stored = json_encode($submission->values);
        $this->assertStringContainsString($keys['consent_transactional'], $stored);

        // Inert on staging: nothing published, nothing enrolled, nothing sent or metered.
        $this->assertSame(0, DB::table('automation_enrollments')->count());
        $this->assertSame(0, DB::table('business_messaging_operations')->count());
        $this->assertSame(0, DB::table('chat_box_messages')->count());
        Mail::assertNothingSent();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->assets.'/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->assets);
        parent::tearDown();
    }
}
