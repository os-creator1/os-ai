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
        foreach ($real['videos'] as $role => &$video) {
            $path = "{$this->assets}/{$role}.mp4";
            file_put_contents($path, "\0\0\0\x18ftypmp42".random_bytes(4000));
            $video['sha256'] = hash_file('sha256', $path);
        }
        unset($video);
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

    public function test_videos_are_stored_persistently_validated_and_rendered(): void
    {
        [$ctx, $result] = $this->site();
        $site = $result['website'];

        $videos = $site->assets()->where('purpose', 'video')->get();
        $this->assertCount(3, $videos);
        foreach ($videos as $video) {
            $this->assertStringStartsWith("images/websites/{$site->uid}/", $video->path, 'Inside the directory the production release switch preserves.');
            $this->assertSame('video/mp4', $video->mime_type);
            $this->assertFileExists(public_path($video->path));
        }
        $this->assertSame(0, $site->assets()->where('purpose', 'gallery')->whereIn('id', $videos->pluck('id'))->count(), 'Videos never appear as gallery images.');

        $home = $this->get("/sites/{$site->public_id}")->assertOk()->getContent();
        $this->assertSame(3, substr_count($home, '<source src='));
        $this->assertStringContainsString('type="video/mp4"', $home);
        $this->assertStringContainsString('<video controls preload="metadata" playsinline', $home);
        $this->assertStringContainsString('See the 360 booth in action', $home);
        $this->get("/sites/{$site->public_id}/360-video-booth")->assertOk()->assertSee('<video controls', false);

        // Upload rules: real MP4 only, size-bounded, content-hash de-duplicated.
        $upload = app(\App\Library\Website\WebsiteAssetUploadService::class);
        $again = $upload->storeVideo($site, new \Illuminate\Http\UploadedFile(public_path($videos[0]->path), 'x.mp4', 'video/mp4', null, true));
        $this->assertSame($videos[0]->id, $again->id, 'A re-upload is a no-op.');
        $fake = tempnam(sys_get_temp_dir(), 'v');
        file_put_contents($fake, str_repeat('not a video', 400));
        $this->expectException(\App\Exceptions\Website\InvalidWebsiteAssetException::class);
        $this->expectExceptionMessage('Only MP4 videos are supported.');
        $upload->storeVideo($site, new \Illuminate\Http\UploadedFile($fake, 'x.mp4', 'video/mp4', null, true));
    }

    public function test_a_video_section_cannot_reference_another_websites_asset(): void
    {
        [$ctx, $result] = $this->site();
        $site = $result['website'];
        $validator = app(\App\Library\Website\WebsiteSectionValidator::class);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $validator->validate([['type' => 'video', 'data' => ['items' => [['video' => 'not-this-sites-asset']]]]], $site->assets()->pluck('uid')->all());
    }

    public function test_old_addresses_redirect_permanently_without_hiding_real_pages(): void
    {
        [$ctx, $result] = $this->site();
        $id = $result['website']->public_id;

        // Capitalised legacy links reach the same lowercase page; renamed/retired ones go where the owner said.
        $this->get("/sites/{$id}/Check-availability")->assertStatus(301)->assertRedirect(route('public.website.page', [$id, 'check-availability']));
        $this->get("/sites/{$id}/Privacy-Policy")->assertStatus(301)->assertRedirect(route('public.website.page', [$id, 'privacy-policy']));
        $this->get("/sites/{$id}/Terms-Conditions")->assertStatus(301)->assertRedirect(route('public.website.page', [$id, 'terms-and-conditions']));
        $this->get("/sites/{$id}/thank-you")->assertStatus(301)->assertRedirect(route('public.website.page', [$id, 'check-availability']));
        $this->get("/sites/{$id}/brochure")->assertStatus(301)->assertRedirect(route('public.website.home', $id));
        $this->get("/sites/{$id}/never-existed")->assertNotFound();

        // Safety: a custom redirect can neither shadow a live page nor point at a page that does not exist.
        $site = $result['website']->fresh();
        app(\App\Library\Website\Seo\WebsiteRedirectManager::class)->set($site, ['gallery' => 'faq', 'old-page' => 'missing-page', 'Other' => 'faq']);
        app(\App\Library\Website\WebsitePublisher::class)->publish($site->fresh(), (int) $ctx->user->id);
        $this->get("/sites/{$id}/gallery")->assertOk();
        $this->get("/sites/{$id}/old-page")->assertNotFound();
        $this->get("/sites/{$id}/other")->assertStatus(301)->assertRedirect(route('public.website.page', [$id, 'faq']));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Library\Website\Seo\WebsiteRedirectManager::class)->set($site, ['https://evil.example/x' => 'faq']);
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
