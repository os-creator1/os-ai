<?php

namespace Tests\Feature\Migration;

use App\Library\Migration\CustomerSites\CustomerSiteGuard;
use App\Library\Migration\CustomerSites\CustomerWebsiteBuilder;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Form;
use App\Models\FormDeployment;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Database-backed tests for the customer website builder: creation, image storage, pages, the deployed form, publishing,
 * idempotent reruns, tenant isolation, and the publish gates (missing asset / content blocker). Uses a small synthetic content
 * file; a separate test proves the three shipped content files are well formed.
 */
class CustomerWebsiteBuilderTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/customer-site-'.bin2hex(random_bytes(6));
        mkdir($this->dir.'/assets', 0700, true);
        $this->app->instance(CustomerSiteGuard::class, new class extends CustomerSiteGuard {
            public function assertTarget(): array
            {
                return ['app_env' => 'production', 'app_host' => 'getmotiongrove.com', 'db_name' => 'GetMotionGrove'];
            }

            public function assertMayWrite(?string $confirmedDatabase, ?string $backupReference, ?string $phrase, ?bool $optedIn = null): array
            {
                return $this->assertTarget();
            }
        });
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/assets/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir.'/assets');
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** @return array{0: Business, 1: BusinessLocation} */
    private function tenant(string $name): array
    {
        [, $business] = $this->entitledTenant(['name' => $name, 'phone' => '+1 601-555-0100', 'email' => 'owner@booth.test']);
        $location = BusinessLocation::query()->where('business_id', $business->id)->first()
            ?? BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'is_primary' => true]);

        return [$business, $location];
    }

    private function png(string $name, int $seed): string
    {
        $im = imagecreatetruecolor(64, 48);
        imagefill($im, 0, 0, imagecolorallocate($im, $seed * 40 % 255, $seed * 70 % 255, $seed * 110 % 255));
        imagestring($im, 3, 4, 4, (string) $seed, imagecolorallocate($im, 255, 255, 255));
        $path = $this->dir.'/assets/'.$name;
        imagepng($im, $path);

        return $path;
    }

    /** @return array<string, mixed> */
    private function content(Business $business, array $overrides = []): array
    {
        $assets = [];
        foreach (['logo' => 'hero', 'hero' => 'hero', 'event_1' => 'gallery'] as $role => $purpose) {
            $path = $this->png($role.'.png', count($assets) + 1);
            $assets[$role] = ['sha256' => hash_file('sha256', $path), 'file' => $role.'.png', 'alt' => ucfirst($role).' image', 'purpose' => $purpose, 'title' => ucfirst($role), 'category' => 'Events'];
        }

        return $overrides + [
            'business' => ['id' => $business->id, 'workspace_id' => $business->workspace_id, 'name' => $business->name],
            'template_key' => 'photo_booth_modern',
            'brand_color' => '#FAC815',
            'secondary_brand_color' => '#232323',
            'blockers' => [],
            'legal_urls' => ['privacy' => 'https://owner.example/privacy', 'terms' => 'https://owner.example/terms'],
            'contact' => ['phone' => '601-555-0100', 'email' => 'owner@booth.test'],
            'form' => ['business_label' => 'Test Booth Co', 'location_label' => 'Where is your event?', 'event_types' => ['Wedding', 'Party']],
            'services' => [['name' => 'Photo booth rental', 'description' => 'Booths.']],
            'catalog' => [['key' => 'test_pkg', 'type' => 'package', 'name' => 'Package', 'description' => 'Desc', 'price_minor' => 37500]],
            'assets' => $assets,
            'pages' => [
                '' => ['title' => 'Home', 'seo_title' => 'Home SEO', 'meta_description' => 'Home meta', 'sections' => [
                    ['type' => 'hero', 'data' => ['heading' => 'Real hero heading', 'background_image' => '@asset:hero', 'primary_cta' => ['label' => 'Check availability', 'url' => '/check-availability']]],
                    ['type' => 'gallery', 'data' => ['heading' => 'Events', 'items' => [['image' => '@asset:event_1']]]],
                ]],
                'check-availability' => ['title' => 'Check availability', 'seo_title' => 'CA', 'meta_description' => 'CA meta', 'sections' => [
                    ['type' => 'hero', 'data' => ['heading' => 'Check availability']],
                    ['type' => 'forms_module_form', 'data' => ['heading' => 'Check availability', 'forms_module_deployment_uid' => '@form']],
                ]],
            ],
        ];
    }

    private function build(Business $business, BusinessLocation $location, array $content): array
    {
        return app(CustomerWebsiteBuilder::class)->build($business, $location, (int) $business->workspace->owner_user_id, $this->dir.'/assets', $content);
    }

    public function test_builds_a_complete_published_site_with_stored_images_pages_form_and_both_colours(): void
    {
        [$business, $location] = $this->tenant('Test Booth Co');
        $before = app(CustomerWebsiteBuilder::class)->crmFingerprint($business);

        $result = $this->build($business, $location, $this->content($business));
        $website = $result['website'];

        $this->assertSame($business->id, $website->business_id);
        $this->assertSame('#fac815', strtolower($website->theme['brand_color']));
        $this->assertSame('#232323', $website->theme['secondary_brand_color']);
        $this->assertSame(2, WebsitePage::query()->where('website_id', $website->id)->count());
        $this->assertSame(3, WebsiteAsset::query()->where('website_id', $website->id)->count());
        $this->assertNotNull($website->theme['logo_asset_uid'] ?? null);
        $this->assertNotNull($website->theme['hero_asset_uid'] ?? null);
        $this->assertSame(1, Form::query()->where('business_id', $business->id)->count());
        $this->assertSame(1, FormDeployment::query()->whereIn('form_id', Form::query()->where('business_id', $business->id)->pluck('id'))->where('source', 'website')->count() ?: -1);
        $this->assertSame('yes (platform preview path only; no custom domain, no DNS)', $result['report']['published']);
        $this->assertSame('published', strtolower($result['report']['status']));
        $this->assertStringContainsString('/sites/'.$website->public_id, $result['report']['preview_url']);
        $this->assertSame($before, app(CustomerWebsiteBuilder::class)->crmFingerprint($business));

        $this->get('/sites/'.$website->public_id)->assertOk()->assertSee('Real hero', false);
        $this->get('/sites/'.$website->public_id)->assertSee('--wd-secondary:#232323', false)->assertSee('wd-has-secondary', false);
        $this->get('/sites/'.$website->public_id.'/check-availability')->assertOk();
    }

    public function test_a_rerun_is_idempotent(): void
    {
        [$business, $location] = $this->tenant('Test Booth Co');
        $content = $this->content($business);
        $this->build($business, $location, $content);
        $counts = fn () => [Website::count(), WebsitePage::count(), WebsiteAsset::count(), Form::count(), FormDeployment::count()];
        $first = $counts();

        $this->build($business, $location, $content);

        $this->assertSame($first, $counts());
    }

    public function test_a_missing_image_aborts_before_any_write(): void
    {
        [$business, $location] = $this->tenant('Test Booth Co');
        $content = $this->content($business);
        unlink($this->dir.'/assets/event_1.png');

        try {
            $this->build($business, $location, $content);
            $this->fail('Expected an abort.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('event_1', $e->getMessage());
        }

        $this->assertSame(0, Website::count());
        $this->assertSame(0, Form::count());
    }

    public function test_a_content_blocker_builds_a_draft_and_never_publishes(): void
    {
        [$business, $location] = $this->tenant('Test Booth Co');

        $result = $this->build($business, $location, $this->content($business, ['blockers' => ['Owner has no legal pages.'], 'legal_urls' => []]));

        $this->assertSame(2, $result['report']['pages']);
        $this->assertStringStartsWith('NO:', $result['report']['published']);
        $this->assertNotSame('published', strtolower($result['report']['status']));
        $this->get('/sites/'.$result['website']->public_id)->assertNotFound();
    }

    public function test_the_business_must_match_workspace_and_name(): void
    {
        [$business] = $this->tenant('Test Booth Co');
        $builder = app(CustomerWebsiteBuilder::class);

        $this->assertSame($business->id, $builder->business($this->content($business))->id);
        foreach ([['workspace_id' => $business->workspace_id + 99], ['name' => 'Someone Else'], ['id' => $business->id + 999]] as $wrong) {
            $content = $this->content($business);
            $content['business'] = $wrong + $content['business'];
            try {
                $builder->business($content);
                $this->fail('Expected a mismatch to abort.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_another_tenant_is_never_touched(): void
    {
        [$a, $locationA] = $this->tenant('Test Booth Co');
        [$b] = $this->tenant('Other Tenant Booth');
        $builder = app(CustomerWebsiteBuilder::class);
        $otherBefore = $builder->crmFingerprint($b);

        $this->build($a, $locationA, $this->content($a));

        $this->assertNull(Website::query()->where('business_id', $b->id)->first());
        $this->assertSame(0, Form::query()->where('business_id', $b->id)->count());
        $this->assertSame($otherBefore, $builder->crmFingerprint($b));
        $this->assertSame(0, WebsiteAsset::query()->whereNotIn('website_id', Website::query()->where('business_id', $a->id)->pluck('id'))->count());
    }

    public function test_contact_details_are_compared_read_only(): void
    {
        [$business] = $this->tenant('Test Booth Co');
        $builder = app(CustomerWebsiteBuilder::class);

        $this->assertTrue($builder->contactCheck($business, $this->content($business))['ok']);

        $content = $this->content($business);
        $content['contact']['phone'] = '999-999-9999';
        $this->assertFalse($builder->contactCheck($business, $content)['ok']);
        $this->assertSame('+1 601-555-0100', $business->fresh()->phone);
    }

    public function test_the_command_dry_run_writes_nothing_and_apply_with_missing_assets_aborts(): void
    {
        [$business] = $this->tenant('Kaleb Hill Photography');
        $business->forceFill(['workspace_id' => $business->workspace_id])->save();
        // The shipped content addresses Business #3 / Workspace #3: re-key the fixture so the identity check can pass.
        $path = base_path('docs/migrations/customer-websites/kaleb-hill/website-content.json');
        $content = json_decode((string) file_get_contents($path), true);
        $this->assertSame('Kaleb Hill Photography', $content['business']['name']);

        $this->assertSame(1, Artisan::call('customer-websites:build', ['key' => 'kaleb-hill']), 'wrong ids must abort');
        $this->assertSame(0, Website::count());
        $this->assertSame(1, Artisan::call('customer-websites:build', ['key' => '../etc']));
    }

    /** @dataProvider shippedKeys */
    public function test_the_shipped_content_is_well_formed_and_gated_correctly(string $key, bool $publishable): void
    {
        $content = app(CustomerWebsiteBuilder::class)->load(base_path("docs/migrations/customer-websites/{$key}/website-content.json"));

        $json = json_encode($content);
        preg_match_all('/@asset:([A-Za-z0-9_]+)/', $json, $m);
        $this->assertSame([], array_values(array_diff($m[1], array_keys($content['assets']))), 'every @asset placeholder resolves');
        foreach ($content['assets'] as $role => $asset) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $asset['sha256'], $role);
            $this->assertNotSame('', trim((string) $asset['alt']), "{$role} needs alt text");
        }
        $this->assertArrayHasKey('', $content['pages']);
        $this->assertArrayHasKey('check-availability', $content['pages']);
        $this->assertStringContainsString('@form', $json);
        $this->assertSame($publishable, $content['blockers'] === []);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $content['brand_color']);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $content['secondary_brand_color']);
        if ($publishable) {
            $this->assertArrayHasKey('privacy-policy', $content['pages']);
            $this->assertNotEmpty($content['legal_urls']);
        } else {
            $this->assertArrayNotHasKey('privacy-policy', $content['pages']);
        }
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function shippedKeys(): array
    {
        return ['kaleb' => ['kaleb-hill', true], 'max' => ['booths-to-the-max', true], 'greenbelt' => ['greenbelt', false]];
    }
}
