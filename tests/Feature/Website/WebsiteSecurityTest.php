<?php

namespace Tests\Feature\Website;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice A — contract §37.7 (Security) plus
 * the asset-upload half of §13: stored-XSS/template-injection closure on
 * the public renderer, the URL scheme allowlist, slug path-traversal
 * rejection, the absence of any remote-URL asset ingestion path, magic-
 * byte/SVG/size asset validation, and cross-Business asset-reference
 * rejection.
 */
class WebsiteSecurityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private function routeParams($workspace, $business, ...$rest): array
    {
        return array_merge([$workspace->uid, $business->uid], $rest);
    }

    // ---------------------------------------------------------------
    // (a) Stored XSS in a text section, published, rendered publicly
    // ---------------------------------------------------------------

    public function test_script_tag_in_text_body_is_escaped_on_the_public_page(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $payload = '<script>alert(1)</script>';

        $this->post(route('customer.workspaces.businesses.website.pages.store', $this->routeParams($workspace, $business)), [
            'title' => 'Home',
            'is_home' => true,
            'sections' => json_encode([$this->section('text', ['body' => $payload])]),
        ])->assertSessionDoesntHaveErrors();

        $this->publisher()->publish($website->fresh(), $customer->user->id);

        $response = $this->get(route('public.website.home', $website->fresh()->public_id));

        $response->assertOk();
        $response->assertDontSee($payload, false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // ---------------------------------------------------------------
    // (b) Dangerous URL schemes rejected on hero primary_cta.url
    // ---------------------------------------------------------------

    public function test_dangerous_url_schemes_are_rejected_on_hero_primary_cta(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $dangerous = [
            'javascript:alert(1)',
            'data:text/html,<script>alert(1)</script>',
            'file:///etc/passwd',
            'vbscript:msgbox(1)',
        ];

        foreach ($dangerous as $url) {
            $this->post(route('customer.workspaces.businesses.website.pages.store', $this->routeParams($workspace, $business)), [
                'title' => 'Home',
                'is_home' => true,
                'sections' => json_encode([$this->section('hero', [
                    'primary_cta' => ['label' => 'Go', 'url' => $url],
                ])]),
            ])->assertSessionHasErrors();

            $this->assertDatabaseMissing('website_pages', ['title' => 'Home']);
        }
    }

    public function test_plain_http_url_is_also_rejected_on_hero_primary_cta(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.pages.store', $this->routeParams($workspace, $business)), [
            'title' => 'Home',
            'is_home' => true,
            'sections' => json_encode([$this->section('hero', [
                'primary_cta' => ['label' => 'Go', 'url' => 'http://example.test'],
            ])]),
        ])->assertSessionHasErrors();
    }

    // ---------------------------------------------------------------
    // (c) Raw HTML / Blade-expression-shaped strings are inert text
    // ---------------------------------------------------------------

    public function test_raw_html_and_blade_expression_shaped_body_is_rendered_as_inert_plain_text(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $body = "<b>bold</b>\n{{ 7*7 }}\n@php system(\"ls\") @endphp";

        $this->post(route('customer.workspaces.businesses.website.pages.store', $this->routeParams($workspace, $business)), [
            'title' => 'Home',
            'is_home' => true,
            'sections' => json_encode([$this->section('text', ['body' => $body])]),
        ])->assertSessionDoesntHaveErrors();

        $this->publisher()->publish($website->fresh(), $customer->user->id);

        $response = $this->get(route('public.website.home', $website->fresh()->public_id));

        $response->assertOk();

        // Never re-evaluated as a second layer of Blade — the string was
        // already stored and output as plain text by page.blade.php's own
        // original {{ }} render, so `{{ 7*7 }}` never gets a chance to be
        // interpreted a second time.
        $response->assertDontSee('49', false);
        $response->assertDontSee('<b>bold</b>', false);
        $response->assertSee('&lt;b&gt;bold&lt;/b&gt;', false);
        $response->assertSee(htmlspecialchars('{{ 7*7 }}', ENT_QUOTES), false);
        $response->assertSee(htmlspecialchars('@php system("ls") @endphp', ENT_QUOTES), false);
    }

    // ---------------------------------------------------------------
    // (d) Slug path traversal rejected
    // ---------------------------------------------------------------

    public function test_path_traversal_slugs_are_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        foreach (['../../etc/passwd', '..%2f..%2fetc'] as $slug) {
            $this->post(route('customer.workspaces.businesses.website.pages.store', $this->routeParams($workspace, $business)), [
                'title' => 'About',
                'slug' => $slug,
                'is_home' => false,
                'sections' => json_encode([$this->section('text')]),
            ])->assertSessionHasErrors('slug');
        }

        $this->assertDatabaseMissing('website_pages', ['title' => 'About']);
    }

    // ---------------------------------------------------------------
    // (e) No remote-URL asset ingestion path exists
    // ---------------------------------------------------------------

    public function test_asset_upload_service_has_no_remote_url_ingestion_path(): void
    {
        $source = file_get_contents(app_path('Library/Website/WebsiteAssetUploadService.php'));

        $this->assertNotFalse($source);
        $this->assertStringNotContainsString('curl', $source);
        $this->assertStringNotContainsString('Http::get', $source);
        $this->assertStringNotContainsString('fopen(', $source);

        // The only file_get_contents() calls in the service read either
        // the locally uploaded file's own real path, or the file this
        // same service just wrote to local disk for its post-write
        // integrity check — never a remote URL.
        $this->assertStringContainsString('file_get_contents($file->getRealPath())', $source);
        $this->assertStringContainsString('file_get_contents($destination)', $source);
        $this->assertSame(2, substr_count($source, 'file_get_contents('));
    }

    // ---------------------------------------------------------------
    // (f) Wrong magic bytes rejected despite a valid .png name/MIME
    // ---------------------------------------------------------------

    public function test_upload_with_png_extension_but_wrong_magic_bytes_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $this->post(route('customer.workspaces.businesses.website.assets.store', $this->routeParams($workspace, $business)), [
            'image' => $this->fakeImageUpload('fake.png', 'not an image bytes'),
        ])->assertSessionHasErrors('image');

        $this->assertSame(0, $website->fresh()->assets()->count());
    }

    // ---------------------------------------------------------------
    // (g) SVG uploads rejected unconditionally, spoofed extension too
    // ---------------------------------------------------------------

    public function test_svg_upload_is_rejected_even_with_a_png_extension(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>';

        $this->post(route('customer.workspaces.businesses.website.assets.store', $this->routeParams($workspace, $business)), [
            'image' => $this->fakeImageUpload('fake.png', $svg),
        ])->assertSessionHasErrors('image');

        $this->post(route('customer.workspaces.businesses.website.assets.store', $this->routeParams($workspace, $business)), [
            'image' => $this->fakeImageUpload('fake.svg', '<?xml version="1.0"?>' . $svg),
        ])->assertSessionHasErrors('image');

        $this->assertSame(0, $website->fresh()->assets()->count());
    }

    // ---------------------------------------------------------------
    // (h) Oversized upload rejected
    // ---------------------------------------------------------------

    public function test_oversized_upload_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->authenticateAsCustomer($customer);

        $maxBytes = \App\Rules\ValidWebsiteImageRule::MAX_SIZE_BYTES;
        $oversized = "\x89PNG\r\n\x1a\n" . str_repeat('a', $maxBytes + 1);

        $this->post(route('customer.workspaces.businesses.website.assets.store', $this->routeParams($workspace, $business)), [
            'image' => $this->fakeImageUpload('big.png', $oversized),
        ])->assertSessionHasErrors('image');

        $this->assertSame(0, $website->fresh()->assets()->count());
    }

    // ---------------------------------------------------------------
    // (i) Cross-Business asset reference rejected
    // ---------------------------------------------------------------

    public function test_referencing_a_foreign_businesss_asset_uid_is_rejected(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->entitledTenant();
        $websiteA = $this->createWebsite($businessA);
        $this->authenticateAsCustomer($customerA);

        $this->post(route('customer.workspaces.businesses.website.assets.store', $this->routeParams($workspaceA, $businessA)), [
            'image' => $this->fakeImageUpload('photo-a.png'),
        ])->assertSessionDoesntHaveErrors();

        $assetA = $websiteA->fresh()->assets()->firstOrFail();

        [$customerB, $businessB, $workspaceB] = $this->entitledTenant();
        $websiteB = $this->createWebsite($businessB);
        $this->authenticateAsCustomer($customerB);

        $this->post(route('customer.workspaces.businesses.website.pages.store', $this->routeParams($workspaceB, $businessB)), [
            'title' => 'Home',
            'is_home' => true,
            'sections' => json_encode([$this->section('hero', [
                'background_image' => $assetA->uid,
            ])]),
        ])->assertSessionHasErrors();

        $this->assertDatabaseMissing('website_pages', ['website_id' => $websiteB->fresh()->id]);
    }

    private function publisher(): \App\Library\Website\WebsitePublisher
    {
        return app(\App\Library\Website\WebsitePublisher::class);
    }
}
