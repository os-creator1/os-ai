<?php

namespace Tests\Feature\Website\Tracking;

use App\Library\Website\Tracking\WebsiteTrackingSettings;
use App\Models\WebsiteTrackingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Seo\Content\Concerns\CreatesContentFixtures;
use Tests\TestCase;

/**
 * Slice 3A — owner tracking (global + page) on PUBLISHED pages and the native blog only, and the verified lead-conversion
 * bridge: only a stored submission of a Website-source deployment is ever announced to the embedding page.
 */
class WebsiteTrackingTest extends TestCase
{
    use CreatesContentFixtures;
    use RefreshDatabase;

    private const HOST = 'http://' . self::DOMAIN;

    protected function setUp(): void
    {
        parent::setUp();

        app('cache')->flush();
    }

    private function tracking(): WebsiteTrackingSettings
    {
        return app(WebsiteTrackingSettings::class);
    }

    /** @return array<string, mixed> the config the page hands the loader, or [] when none is emitted */
    private function config(string $html): array
    {
        if (! preg_match('#<script type="application/json" id="mg-tracking-config">(.*?)</script>#s', $html, $m)) {
            return [];
        }

        return json_decode($m[1], true);
    }

    private function global(array $extra = []): array
    {
        return array_merge(['google_tag_id' => 'G-ABC123XYZ', 'google_ads_id' => 'AW-123456789', 'google_ads_lead_label' => 'AbCdEfGhIj', 'meta_pixel_id' => '123456789012345'], $extra);
    }

    // ------------------------------------------------------------------ rendering

    public function test_global_tracking_loads_on_published_pages_and_the_native_blog_through_one_loader(): void
    {
        [, $business, , $website] = $this->photoBoothContentTenant();
        $article = $this->publishedArticle($business);
        $this->tracking()->save($website, '*', $this->global());

        foreach (['/', '/service-wedding-photo-booth-rental', '/blog', '/blog/' . $article->slug] as $path) {
            $html = $this->get(self::HOST . $path)->assertOk()->getContent();
            $config = $this->config($html);

            $this->assertSame(['G-ABC123XYZ', 'AW-123456789'], $config['google_tag_ids'], $path);
            $this->assertSame(['123456789012345'], $config['meta_pixel_ids'], $path);
            $this->assertSame([['id' => 'AW-123456789', 'label' => 'AbCdEfGhIj']], $config['ads_conversions'], $path);
            $this->assertSame(1, substr_count($html, 'js/website-tracking.js'), $path);
            $this->assertStringNotContainsString('googletagmanager.com', $html, 'the page itself loads no third-party script; the consent-aware loader does');
            $this->assertStringNotContainsString('connect.facebook.net', $html);
        }
    }

    public function test_page_tracking_adds_to_global_without_loading_the_same_tag_or_pixel_twice(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', $this->global());
        $this->tracking()->save($website, 'home', ['google_tag_id' => 'G-ABC123XYZ', 'meta_pixel_id' => '123456789012345']);
        $this->tracking()->save($website, 'photo-booth-contact', ['meta_pixel_id' => '999999999999']);

        $home = $this->config($this->get(self::HOST . '/')->getContent());
        $this->assertSame(['G-ABC123XYZ', 'AW-123456789'], $home['google_tag_ids'], 'the same Google tag is listed once');
        $this->assertSame(['123456789012345'], $home['meta_pixel_ids'], 'the same pixel is listed once');

        $contact = $this->config($this->get(self::HOST . '/photo-booth-contact')->getContent());
        $this->assertSame(['123456789012345', '999999999999'], $contact['meta_pixel_ids'], 'a page adds its own pixel to the inherited one');

        $other = $this->config($this->get(self::HOST . '/photo-booth-about')->getContent());
        $this->assertSame(['123456789012345'], $other['meta_pixel_ids'], 'other pages only inherit the global setting');
    }

    public function test_disabled_or_removed_tracking_emits_nothing(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', $this->global(['is_enabled' => false]));
        $this->assertSame([], $this->config($this->get(self::HOST . '/')->getContent()));

        $this->tracking()->save($website, '*', $this->global());
        $this->assertNotSame([], $this->config($this->get(self::HOST . '/')->getContent()));

        $this->tracking()->remove($website, '*');
        $html = $this->get(self::HOST . '/')->getContent();
        $this->assertSame([], $this->config($html));
        $this->assertStringNotContainsString('website-tracking.js', $html);
    }

    public function test_the_owner_preview_and_unpublished_state_never_run_tracking(): void
    {
        [$customer, , $workspace, $website] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', $this->global());
        $this->assertNull($this->tracking()->effective($website, 'home', false, true), 'a preview/unpublished render gets no tracking at all');

        $page = $website->pages()->create(['title' => 'Home', 'slug' => null, 'is_home' => true, 'sort_order' => 0, 'noindex' => false, 'sections' => [['type' => 'text', 'data' => ['heading' => 'Hi', 'body' => 'Draft copy.']]]]);
        $this->authenticateAsSeoCustomer($customer);

        $html = $this->get(route('customer.workspaces.businesses.website.preview', [$workspace->uid, $website->business->uid, $page->uid]))->assertOk()->getContent();

        $this->assertSame([], $this->config($html));
        $this->assertStringNotContainsString('website-tracking.js', $html);
    }

    public function test_owner_code_runs_only_on_the_owners_own_domain_never_on_the_shared_platform_origin(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $snippet = '<script>window.ownerPixel=1;</script>';
        $this->tracking()->save($website, '*', $this->global(['head_snippet' => $snippet]));

        $own = $this->config($this->get(self::HOST . '/')->getContent());
        $this->assertSame([$snippet], $own['snippets']);

        // The same site, on the platform path (the dashboard's origin): structured tags only, no owner code.
        $platform = $this->tracking()->effective($website, 'home', true, false);
        $this->assertSame([], $platform['snippets']);
        $this->assertSame(['G-ABC123XYZ', 'AW-123456789'], $platform['google_tag_ids']);
    }

    public function test_the_config_block_cannot_be_broken_out_of_by_owner_text(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', ['head_snippet' => '<script>var a = "</scr" + "ipt><b>x</b>";</script>']);

        $html = $this->get(self::HOST . '/')->getContent();

        $this->assertSame(1, substr_count($html, 'id="mg-tracking-config"'));
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertNotEmpty($this->config($html)['snippets']);
    }

    // ------------------------------------------------------------------ validation

    public function test_identifiers_and_owner_code_are_validated_and_length_limited(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();

        foreach ([
            ['google_tag_id' => 'UA-1'],
            ['google_ads_id' => '123'],
            ['google_ads_lead_label' => 'abcdef123', 'google_ads_id' => null],
            ['meta_pixel_id' => 'pixel'],
            ['head_snippet' => '<iframe src="https://x.example"></iframe>'],
            ['head_snippet' => '<script src="http://x.example/a.js"></script>'],
            ['head_snippet' => '<div>hi</div>'],
            ['head_snippet' => '<?php echo 1; ?>'],
            ['head_snippet' => '<!-- c --><script>1</script>'],
            ['head_snippet' => '<script>' . str_repeat('a', WebsiteTrackingSettings::MAX_SNIPPET) . '</script>'],
        ] as $bad) {
            try {
                $this->tracking()->save($website, '*', $bad);
                $this->fail('Expected refusal for ' . json_encode($bad));
            } catch (ValidationException) {
                $this->assertSame(0, WebsiteTrackingSetting::query()->count());
            }
        }

        $this->tracking()->save($website, '*', ['head_snippet' => '<script async src="https://example.com/t.js"></script><script>for(var i=0;i<3;i++){window.n=i}</script>']);
        $this->assertSame(1, WebsiteTrackingSetting::query()->count(), 'ordinary tag-manager style code, including `i<3` in a script body, is accepted');
    }

    // ------------------------------------------------------------------ owner screen + tenancy + survival

    public function test_the_owner_manages_tracking_from_the_website_tracking_tab_and_another_tenant_cannot(): void
    {
        [$customer, , $workspace, $website] = $this->photoBoothContentTenant();
        [$intruder] = $this->photoBoothContentTenant();
        $url = route('customer.workspaces.businesses.website.tracking.index', [$workspace->uid, $website->business->uid]);
        $save = route('customer.workspaces.businesses.website.tracking.save', [$workspace->uid, $website->business->uid]);

        $this->authenticateAsSeoCustomer($customer);
        $this->get($url)->assertOk()->assertSee('Tracking for the whole website')->assertSee('data-testid="lead-form-status"', false);
        $this->post($save, ['scope' => '*', 'is_enabled' => '1', 'require_consent' => '1', 'meta_pixel_id' => '123456789012345'])->assertSessionHasNoErrors();
        $this->assertSame('123456789012345', $this->tracking()->find($website, '*')->meta_pixel_id);
        $this->post($save, ['scope' => '*', 'meta_pixel_id' => 'nope'])->assertSessionHasErrors('meta_pixel_id');
        $this->post($save, ['scope' => 'no-such-page', 'meta_pixel_id' => '123456789012345'])->assertNotFound();

        $this->authenticateAsSeoCustomer($intruder);
        $this->post($save, ['scope' => '*', 'meta_pixel_id' => '555555555555'])->assertStatus(404);
        $this->assertSame('123456789012345', $this->tracking()->find($website, '*')->meta_pixel_id, 'a foreign tenant changed nothing');

        $this->authenticateAsSeoCustomer($customer);
        $this->delete(route('customer.workspaces.businesses.website.tracking.destroy', [$workspace->uid, $website->business->uid]), ['scope' => '*'])->assertRedirect();
        $this->assertNull($this->tracking()->find($website, '*'));
    }

    public function test_settings_survive_regeneration_and_never_reach_another_tenants_site(): void
    {
        [, , , $website] = $this->photoBoothContentTenant();
        [, , , $other] = $this->photoBoothContentTenant();
        $this->tracking()->save($website, '*', $this->global());
        $this->tracking()->save($website, 'photo-booth-contact', ['meta_pixel_id' => '999999999999']);

        // Regeneration replaces every draft page row (new uids); settings are keyed by page identity, not by row.
        $website->pages()->delete();
        $website->pages()->create(['title' => 'Contact', 'slug' => 'photo-booth-contact', 'is_home' => false, 'sort_order' => 1, 'noindex' => false, 'sections' => [['type' => 'text', 'data' => ['heading' => 'Contact', 'body' => 'Hello.']]]]);

        $this->assertSame(2, WebsiteTrackingSetting::query()->where('website_id', $website->id)->count());
        $this->assertSame(['123456789012345', '999999999999'], $this->tracking()->effective($website->fresh(), 'photo-booth-contact', true, true)['meta_pixel_ids']);
        $this->assertNull($this->tracking()->effective($other, 'home', true, true), 'another tenant has no tracking');
    }
}
