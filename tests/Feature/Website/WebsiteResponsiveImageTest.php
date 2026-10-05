<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Exceptions\Website\InvalidWebsiteAssetException;
use App\Library\Business\BusinessImageStore;
use App\Library\Website\Design\WebsiteDesigns;
use App\Library\Website\Design\WebsiteLookService;
use App\Library\Website\Media\ImageVariants;
use App\Library\Website\Media\ResponsiveImage;
use App\Library\Website\Media\WebsiteMediaPayload;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\WebsiteAsset;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website V1 final — responsive image delivery: WebP derivatives next to the
 * original, never upscaled, srcset/sizes with explicit dimensions, the hero
 * eager and everything else lazy, the same output in all four templates, a
 * safe fallback for anything that has no derivatives, and the existing
 * upload safety (ownership, real image bytes, size and dimension ceilings).
 *
 * Runs against a disposable public/ root, so no repository file is touched.
 */
class WebsiteResponsiveImageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;

    private string $publicRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->publicRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'website-v1-images-' . uniqid();
        mkdir($this->publicRoot, 0755, true);
        $this->app->usePublicPath($this->publicRoot);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->publicRoot);
        parent::tearDown();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    /** A real, noisy (so genuinely large) image. */
    private function image(int $width, int $height, string $type = 'jpg', string $name = 'photo'): UploadedFile
    {
        $im = imagecreatetruecolor($width, $height);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        $background = $type === 'png-alpha' ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, 30, 60, 120);
        imagefilledrectangle($im, 0, 0, $width, $height, $background);

        mt_srand($width * 31 + $height);
        for ($i = 0; $i < 260; $i++) {
            $color = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagefilledellipse($im, mt_rand(0, $width), mt_rand(0, $height), mt_rand(20, max(40, intdiv($width, 3))), mt_rand(20, max(40, intdiv($height, 3))), $color);
        }

        $path = tempnam(sys_get_temp_dir(), 'img-');
        $type === 'jpg' ? imagejpeg($im, $path, 92) : imagepng($im, $path, 1);
        imagedestroy($im);

        return new UploadedFile($path, $name . ($type === 'jpg' ? '.jpg' : '.png'), $type === 'jpg' ? 'image/jpeg' : 'image/png', null, true);
    }

    private function website(array $businessOverrides = []): array
    {
        [$customer, $business] = $this->entitledTenant($businessOverrides + ['name' => 'Luma Photo Booth Co', 'phone' => '3125550188', 'email' => 'hello@lumabooth.test']);
        $website = app(WebsiteStarterDraftService::class)->createShellFromTemplate($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'));

        return [$customer, $business, $website];
    }

    /** @return array<int, array{w: int, h: int, path: string}> */
    private function variantsOf(WebsiteAsset $asset): array
    {
        return app(ImageVariants::class)->existing($asset->path);
    }

    public function test_a_large_photo_gets_webp_derivatives_at_every_step_and_keeps_its_original(): void
    {
        [, , $website] = $this->website();

        $asset = app(WebsiteAssetUploadService::class)->store($website, $this->image(3000, 2000), 'A big photo');
        $variants = $this->variantsOf($asset);

        $this->assertSame([480, 768, 1280, 1920], array_column($variants, 'w'));
        $this->assertFileExists(public_path($asset->path), 'The original is kept.');

        foreach ($variants as $variant) {
            $file = public_path($variant['path']);
            $info = getimagesize($file);
            $this->assertSame(IMAGETYPE_WEBP, $info[2], 'Derivatives are WebP.');
            $this->assertSame($variant['w'], $info[0]);
            $this->assertSame($variant['h'], $info[1]);
            $this->assertEqualsWithDelta(2 / 3, $variant['h'] / $variant['w'], 0.003, 'Aspect ratio is preserved.');
            $this->assertLessThan(filesize(public_path($asset->path)), filesize($file), 'A derivative is smaller than the original.');
        }
        $this->assertLessThan(filesize(public_path($asset->path)) / 8, filesize(public_path($variants[0]['path'])), 'The phone-size derivative is a small fraction of the original.');
    }

    public function test_a_smaller_source_is_never_upscaled(): void
    {
        [, , $website] = $this->website();
        $uploads = app(WebsiteAssetUploadService::class);

        $mid = $uploads->store($website, $this->image(600, 400), 'Mid');
        $this->assertSame([480, 600], array_column($this->variantsOf($mid), 'w'), 'Only narrower steps plus the source\'s own width.');

        $tiny = $uploads->store($website, $this->image(300, 200, 'jpg', 'tiny'), 'Tiny');
        foreach ($this->variantsOf($tiny) as $variant) {
            $this->assertLessThanOrEqual(300, $variant['w'], 'Nothing is ever wider than the source.');
        }

        $exact = $uploads->store($website, $this->image(1920, 1080), 'Exact');
        $this->assertSame([480, 768, 1280, 1920], array_column($this->variantsOf($exact), 'w'));

        $huge = $uploads->store($website, $this->image(3600, 2400, 'jpg', 'huge'), 'Huge');
        $this->assertSame(1920, max(array_column($this->variantsOf($huge), 'w')), 'Wider sources are capped at 1920.');
    }

    public function test_a_logo_only_gets_the_two_small_steps(): void
    {
        [, , $website] = $this->website();

        $logo = app(WebsiteLookService::class)->setLogo($website, $this->image(1200, 360, 'png', 'logo'), 'Luma logo');

        $this->assertSame([240, 480], array_column($this->variantsOf($logo), 'w'), 'A logo shown ~210px wide never needs 1920px.');
    }

    public function test_transparency_survives_in_the_derivatives(): void
    {
        [, , $website] = $this->website();

        // A transparent canvas with one small opaque circle in the middle.
        $canvas = imagecreatetruecolor(1000, 600);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagefilledellipse($canvas, 500, 300, 200, 200, imagecolorallocatealpha($canvas, 200, 30, 30, 0));
        $path = tempnam(sys_get_temp_dir(), 'alpha-');
        imagepng($canvas, $path);
        $asset = app(WebsiteAssetUploadService::class)->store($website, new UploadedFile($path, 'cutout.png', 'image/png', null, true), 'Transparent');
        $variant = $this->variantsOf($asset)[0];
        $im = imagecreatefromwebp(public_path($variant['path']));
        imagealphablending($im, false);
        $this->assertSame(IMAGETYPE_WEBP, getimagesize(public_path($variant['path']))[2]);

        $this->assertGreaterThan(0, (imagecolorat($im, 2, 2) >> 24) & 0x7F, 'A transparent corner stays transparent.');
    }

    public function test_a_phone_photo_with_exif_rotation_is_derived_the_right_way_up(): void
    {
        [, , $website] = $this->website();
        $upload = $this->image(800, 400);
        // Orientation 6 = "rotate 90° clockwise to view": the real picture is portrait.
        $jpeg = file_get_contents($upload->getRealPath());
        $exif = "Exif\0\0" . 'II' . pack('v', 42) . pack('V', 8) . pack('v', 1) . pack('v', 0x0112) . pack('v', 3) . pack('V', 1) . pack('v', 6) . pack('v', 0) . pack('V', 0);
        file_put_contents($upload->getRealPath(), substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2));

        $asset = app(WebsiteAssetUploadService::class)->store($website, $upload, 'Portrait');
        $variant = collect($this->variantsOf($asset))->last();

        $this->assertGreaterThan($variant['w'], $variant['h'], 'The derivative is portrait, as a browser shows the original.');
        $this->assertSame(400, $variant['w']);
        $this->assertSame(800, $variant['h']);
    }

    public function test_the_img_tag_has_srcset_sizes_dimensions_and_the_right_loading_strategy(): void
    {
        $asset = [
            'url' => 'https://x.test/images/o.jpg', 'alt_text' => 'A "quoted" <alt>', 'width' => 3000, 'height' => 2000,
            'variants' => [['h' => 320, 'w' => 480, 'url' => 'https://x.test/v/o-480x320.webp'], ['h' => 853, 'w' => 1280, 'url' => 'https://x.test/v/o-1280x853.webp'], ['h' => 1280, 'w' => 1920, 'url' => 'https://x.test/v/o-1920x1280.webp']],
        ];

        $lazy = (string) ResponsiveImage::tag($asset, '(min-width: 960px) 33vw, 50vw');
        $this->assertStringContainsString('src="https://x.test/v/o-1280x853.webp"', $lazy, 'The plain src is the ~1280 derivative, never the original.');
        $this->assertStringContainsString('srcset="https://x.test/v/o-480x320.webp 480w, https://x.test/v/o-1280x853.webp 1280w, https://x.test/v/o-1920x1280.webp 1920w"', $lazy);
        $this->assertStringContainsString('sizes="(min-width: 960px) 33vw, 50vw"', $lazy);
        $this->assertStringContainsString('width="1280" height="853"', $lazy);
        $this->assertStringContainsString('loading="lazy"', $lazy);
        $this->assertStringContainsString('alt="A &quot;quoted&quot; &lt;alt&gt;"', $lazy, 'Alt text is escaped.');

        $hero = (string) ResponsiveImage::tag($asset, '100vw', ['eager' => true, 'priority' => true]);
        $logo = (string) ResponsiveImage::tag($asset, '210px', ['eager' => true]);
        $this->assertStringNotContainsString('fetchpriority', $logo, 'An eager logo does not compete with the hero.');
        $this->assertStringNotContainsString('loading="lazy"', $logo);
        $this->assertStringContainsString('fetchpriority="high"', $hero);
        $this->assertStringNotContainsString('loading="lazy"', $hero, 'The hero is never lazy.');

        $decorative = (string) ResponsiveImage::tag($asset, '100vw', ['alt' => '']);
        $this->assertStringContainsString('alt=""', $decorative);
    }

    public function test_an_asset_without_variants_renders_its_original_safely(): void
    {
        $tag = (string) ResponsiveImage::tag(['url' => 'https://x.test/images/old.jpg', 'alt_text' => 'Old', 'width' => 1200, 'height' => 800], '100vw');

        $this->assertStringContainsString('src="https://x.test/images/old.jpg"', $tag);
        $this->assertStringNotContainsString('srcset', $tag);
        $this->assertStringContainsString('width="1200" height="800"', $tag);

        $bare = (string) ResponsiveImage::tag(['url' => 'https://x.test/images/older.jpg'], '100vw');
        $this->assertStringNotContainsString('width=', $bare, 'No dimensions known: none invented.');
        $this->assertStringContainsString('src="https://x.test/images/older.jpg"', $bare);
    }

    public function test_an_older_published_revision_with_no_variants_still_renders_and_is_never_rewritten(): void
    {
        [, , $website] = $this->website();
        $asset = app(WebsiteAssetUploadService::class)->store($website, $this->image(2000, 1200), 'Event');
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('image_text', ['image' => $asset->uid, 'heading' => 'Made for events', 'body' => 'Our story.'])]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        // Turn the published snapshot into what a pre-responsive-images revision looked like.
        $revision = $website->fresh()->publishedRevision;
        $snapshot = $revision->snapshot;
        $snapshot['assets'] = array_map(fn ($a) => ['uid' => $a['uid'], 'url' => $a['url'], 'alt_text' => $a['alt_text']], $snapshot['assets']);
        $revision->forceFill(['snapshot' => $snapshot])->save();

        $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

        $this->assertStringContainsString('src="' . $asset->url() . '"', $html, 'The original is served.');
        $this->assertStringNotContainsString('srcset', $html);
        $this->assertSame($snapshot['assets'], $website->fresh()->publishedRevision->snapshot['assets'], 'Rendering never rewrites a published revision.');
    }

    public function test_publishing_gives_an_older_asset_its_derivatives_without_touching_the_original(): void
    {
        [, , $website] = $this->website();
        $asset = app(WebsiteAssetUploadService::class)->store($website, $this->image(2500, 1500), 'Event');
        $originalHash = hash_file('sha256', public_path($asset->path));
        app(ImageVariants::class)->delete($asset->path); // an asset uploaded before this feature
        $this->assertSame([], $this->variantsOf($asset));

        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('image_text', ['image' => $asset->uid, 'body' => 'Our story.'])]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

        $frozen = collect($website->fresh()->publishedRevision->snapshot['assets'])->firstWhere('uid', $asset->uid);
        $this->assertSame([480, 768, 1280, 1920], array_column($frozen['variants'], 'w'), 'Frozen into the revision.');
        $this->assertSame($originalHash, hash_file('sha256', public_path($asset->path)), 'The original is byte-identical.');
    }

    public function test_a_gif_is_refused_at_upload_so_no_animated_source_ever_needs_resizing(): void
    {
        [, , $website] = $this->website();
        $gif = new UploadedFile($this->tmpFile(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')), 'dot.gif', 'image/gif', null, true);

        $this->expectException(InvalidWebsiteAssetException::class);
        app(WebsiteAssetUploadService::class)->store($website, $gif, 'Dot');
    }

    private function tmpFile(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'raw-');
        file_put_contents($path, $bytes);

        return $path;
    }

    public function test_upload_safety_is_unchanged_real_image_bytes_and_size_ceilings(): void
    {
        [, , $website] = $this->website();
        $uploads = app(WebsiteAssetUploadService::class);

        // Not an image, whatever the extension / client MIME claims.
        $fake = new UploadedFile($this->tmpFile('<?php echo "hi";'), 'photo.jpg', 'image/jpeg', null, true);
        $this->expectException(InvalidWebsiteAssetException::class);

        try {
            $uploads->store($website, $fake, 'x');
        } finally {
            $this->assertSame(0, $website->assets()->count());
            $this->assertSame([], glob($this->publicRoot . '/images/websites/*/*') ?: [], 'No file is left behind.');
        }
    }

    public function test_an_svg_and_an_oversized_image_are_refused_at_the_upload_boundary(): void
    {
        [, $business, $website] = $this->website();
        $svg = new UploadedFile($this->tmpFile('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'), 'logo.svg', 'image/svg+xml', null, true);

        try {
            app(WebsiteAssetUploadService::class)->store($website, $svg, 'x');
            $this->fail('An SVG must be refused.');
        } catch (InvalidWebsiteAssetException) {
            $this->assertSame(0, $website->assets()->count());
        }

        $this->expectException(InvalidWebsiteAssetException::class);
        app(BusinessImageStore::class)->store($business, $this->image(4001, 40, 'png', 'wide'));
    }

    public function test_a_foreign_asset_cannot_be_referenced_published_or_deleted(): void
    {
        [, , $mine] = $this->website();
        [, , $theirs] = $this->website();
        $foreign = app(WebsiteAssetUploadService::class)->store($theirs, $this->image(1600, 900), 'Theirs');
        $this->homePage($mine, ['sections' => [$this->section('hero'), $this->section('image_text', ['image' => $foreign->uid, 'body' => 'x'])]]);

        try {
            app(WebsitePublisher::class)->publish($mine, $this->platformAdminId());
            $this->fail('A foreign asset reference must not publish.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('foreign', collect($e->errors())->flatten()->implode(' '));
        }

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(WebsiteAssetUploadService::class)->delete($mine, $foreign);
    }

    public function test_deleting_an_asset_removes_its_derivatives_too(): void
    {
        [, , $website] = $this->website();
        $asset = app(WebsiteAssetUploadService::class)->store($website, $this->image(2000, 1200), 'Gone');
        $paths = array_column($this->variantsOf($asset), 'path');
        $this->assertNotEmpty($paths);

        app(WebsiteAssetUploadService::class)->delete($website, $asset);

        foreach ($paths as $path) {
            $this->assertFileDoesNotExist(public_path($path));
        }
        $this->assertFileDoesNotExist(public_path($asset->path));
    }

    public function test_business_owned_backdrop_and_package_pictures_get_derivatives_and_share_them_with_a_mirror(): void
    {
        [, $business, $website] = $this->website();
        $stored = app(BusinessImageStore::class)->store($business, $this->image(2400, 1600));

        $variants = app(ImageVariants::class)->existing($stored['path']);
        $this->assertSame([480, 768, 1280, 1920], array_column($variants, 'w'));

        // A package picture mirrored into a Website asset shares the very same derivatives.
        $mirror = WebsiteAsset::forceCreate(['website_id' => $website->id, 'disk' => 'public', 'path' => $stored['path'], 'mime_type' => $stored['mime_type'], 'size' => $stored['size'], 'width' => $stored['width'], 'height' => $stored['height'], 'content_hash' => str_repeat('c', 64), 'purpose' => WebsiteAssetPurpose::PackageMirror->value]);
        $this->assertSame([480, 768, 1280, 1920], array_column(app(WebsiteMediaPayload::class)->forAsset($mirror)['variants'], 'w'));

        // And a backdrops section (plain Business image URLs) is enriched the same way.
        $sections = app(WebsiteMediaPayload::class)->enrichSections([['type' => 'backdrops', 'data' => ['items' => [['name' => 'Floral', 'availability' => true, 'images' => [['url' => asset($stored['path']), 'alt_text' => 'Floral wall'], ['url' => 'https://elsewhere.test/x.jpg', 'alt_text' => 'External']]]]]]]);
        $images = $sections[0]['data']['items'][0]['images'];
        $this->assertSame(2400, $images[0]['width']);
        $this->assertCount(4, $images[0]['variants']);
        $this->assertArrayNotHasKey('variants', $images[1], 'A URL that is not one of our stored images is left alone.');

        app(BusinessImageStore::class)->deleteIfUnreferenced($business, $stored['path']);
        $this->assertSame([], app(ImageVariants::class)->existing($stored['path']), 'An unreferenced picture takes its derivatives with it.');
    }

    public function test_all_four_templates_serve_the_same_optimised_images_with_the_hero_eager_and_the_rest_lazy(): void
    {
        [, , $website] = $this->website();
        $uploads = app(WebsiteAssetUploadService::class);
        $look = app(WebsiteLookService::class);

        $look->setLogo($website, $this->image(1200, 360, 'png', 'logo'), 'Luma logo');
        $look->setHero($website->fresh(), $this->image(3200, 2000, 'jpg', 'hero'), 'Guests in the booth');
        $photo = $uploads->store($website, $this->image(2200, 1500, 'jpg', 'event'), 'A wedding');
        $gallery = [];
        for ($i = 0; $i < 6; $i++) {
            $gallery[] = ['image' => $uploads->store($website, $this->image(1800 + $i, 1200, 'jpg', 'g' . $i), 'Photo ' . $i)->uid];
        }
        $this->homePage($website, ['seo_title' => 'Luma', 'meta_description' => 'Photo booths.', 'sections' => [
            $this->section('hero', ['heading' => 'Photo booths that make your event unforgettable']),
            $this->section('services', ['heading' => 'Booths', 'items' => [['name' => 'Open-Air', 'description' => 'Roomy.', 'price_label' => null, 'image' => $photo->uid]]]),
            $this->section('image_text', ['heading' => 'Made for events', 'body' => 'Our story.', 'image' => $photo->uid]),
            $this->section('gallery', ['heading' => 'Photos', 'items' => $gallery]),
            $this->section('contact_details'),
        ]]);

        foreach (array_keys(WebsiteDesigns::all()) as $templateKey) {
            app(WebsiteStarterDraftService::class)->changeTemplateKeepingPages($website, WebsiteTemplate::findActiveOrFail($templateKey));
            app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
            $html = $this->get(route('public.website.home', $website->public_id))->assertOk()->getContent();

            preg_match_all('/<img\b[^>]*>/', $html, $matches);
            $images = $matches[0];
            $this->assertNotEmpty($images);

            foreach ($images as $img) {
                $this->assertStringContainsString('srcset="', $img, "{$templateKey}: every image is responsive: {$img}");
                $this->assertMatchesRegularExpression('/ width="\d+" height="\d+"/', $img, "{$templateKey}: explicit dimensions, no layout shift.");
                $this->assertStringContainsString('.webp', $img, "{$templateKey}: derivatives are served, not the original.");
                $this->assertStringNotContainsString('/' . basename($photo->path) . '"', $img);
            }

            $hero = collect($images)->first(fn ($i) => str_contains($i, 'fetchpriority="high"'));
            $this->assertNotNull($hero, "{$templateKey}: the hero image is high priority.");
            $this->assertStringNotContainsString('loading="lazy"', $hero, "{$templateKey}: the hero is eager.");
            $this->assertSame(1, substr_count(implode('', $images), 'fetchpriority="high"'), "{$templateKey}: exactly one prioritised image.");

            $logo = collect($images)->first(fn ($i) => str_contains($i, 'wd-logo-img'));
            $this->assertNotNull($logo);
            $this->assertStringContainsString('240w', $logo);
            $this->assertStringNotContainsString('loading="lazy"', $logo, "{$templateKey}: the header logo is eager.");

            $belowTheFold = collect($images)->reject(fn ($i) => str_contains($i, 'fetchpriority="high"') || str_contains($i, 'wd-logo-img'));
            $this->assertGreaterThanOrEqual(8, $belowTheFold->count());
            foreach ($belowTheFold as $img) {
                $this->assertStringContainsString('loading="lazy"', $img, "{$templateKey}: below-the-fold images are lazy.");
            }

            $this->assertStringNotContainsString('background-image', $html, "{$templateKey}: no unoptimisable CSS background photo.");
        }
    }

    public function test_the_draft_preview_serves_the_same_derivatives(): void
    {
        [$customer, $business, $website] = $this->website();
        $asset = app(WebsiteAssetUploadService::class)->store($website, $this->image(2400, 1600), 'Event');
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('image_text', ['image' => $asset->uid, 'body' => 'Our story.'])]]);
        $this->authenticateAsCustomer($customer);

        $html = $this->get(route('customer.workspaces.businesses.website.preview', [\App\Models\Workspace::find($business->workspace_id)->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('srcset="', $html);
        $this->assertStringContainsString('.webp', $html);
    }
}
