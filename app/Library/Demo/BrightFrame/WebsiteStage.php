<?php

namespace App\Library\Demo\BrightFrame;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Website\Design\WebsiteLookService;
use App\Library\Website\Gallery\WebsiteGalleryManager;
use App\Library\Website\Media\ImageVariants;
use App\Library\Website\Media\WebsiteMediaPayload;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;

/**
 * A complete fictional website, built only with the existing website
 * services (starter-draft builder, draft page service, look service, asset
 * upload, publisher). The AI website-generation action is never touched.
 * Re-running rewrites pages only where their content differs, and publishes
 * a new revision only when something actually changed.
 */
final class WebsiteStage implements DemoStage
{
    private const TEMPLATE_KEY = 'photo_booth_modern';

    /** [palette, title, category, alt text] */
    private const GALLERY = [
        ['wedding', 'Blush wedding reception', 'Weddings', 'Illustrated photo strip at a blush-toned wedding reception'],
        ['glam', 'Glam mirror booth night', 'Weddings', 'Illustrated photo strip with warm golden glam lighting'],
        ['garden', 'Garden ceremony after-party', 'Weddings', 'Illustrated photo strip against soft garden greens'],
        ['corporate', 'Product launch activation', 'Corporate', 'Illustrated photo strip in teal corporate event colors'],
        ['neon', '360 video booth launch party', 'Corporate', 'Illustrated photo strip under neon launch-party lights'],
        ['corporate', 'Company holiday party', 'Corporate', 'Illustrated photo strip at a company celebration'],
        ['birthday', 'Milestone birthday bash', 'Birthdays', 'Illustrated photo strip with bright birthday colors'],
        ['birthday', 'Sweet sixteen celebration', 'Birthdays', 'Illustrated photo strip with party confetti tones'],
        ['prom', 'Prom night photo strip', 'Proms and schools', 'Illustrated photo strip with midnight prom lighting'],
        ['prom', 'Graduation open house', 'Proms and schools', 'Illustrated photo strip with deep purple graduation tones'],
        ['wedding', 'Rehearsal dinner keepsakes', 'Weddings', 'Illustrated photo strip in soft rose tones'],
        ['neon', 'Brand activation weekend', 'Corporate', 'Illustrated photo strip with vivid neon brand colors'],
    ];

    private bool $dirty = false;

    public function __construct(
        private readonly WebsiteStarterDraftService $starter,
        private readonly WebsiteDraftPageService $pages,
        private readonly WebsiteLookService $look,
        private readonly WebsiteAssetUploadService $uploads,
        private readonly WebsiteGalleryManager $gallery,
        private readonly WebsitePublisher $publisher,
    ) {
    }

    public function run(DemoContext $context): void
    {
        $business = $context->business;
        $this->dirty = false;

        $template = WebsiteTemplate::query()->where('key', self::TEMPLATE_KEY)->first()
            ?? WebsiteTemplate::create(collect(WebsiteTemplateSeeder::templates())->firstWhere('key', self::TEMPLATE_KEY));

        $website = Website::query()->where('business_id', $business->id)->first();

        if ($website === null) {
            $website = $this->starter->createFromTemplate($business, $template);
            $this->dirty = true;
        }

        $website = $website->fresh();

        // Look: brand colour, logo and hero.
        if (($website->theme['brand_color'] ?? null) !== '#7c3aed') {
            $this->look->setBrandColor($website, '#7c3aed');
            $this->dirty = true;
        }
        $website = $website->fresh();

        if (! $this->assetExists($website, $website->theme['logo_asset_uid'] ?? null)) {
            $this->look->setLogo($website, DemoImageFactory::logo(), 'BrightFrame Photo Booths logo');
            $this->dirty = true;
        }
        $website = $website->fresh();

        if (! $this->assetExists($website, $website->theme['hero_asset_uid'] ?? null)) {
            $this->look->setHero($website, DemoImageFactory::scene('hero', 1920, 1080, 1, 'hero.jpg'), 'Illustrated photo booth strip over purple event lights');
            $this->dirty = true;
        }
        $website = $website->fresh();

        // Gallery.
        $galleryUids = [];
        foreach (self::GALLERY as $i => [$palette, $title, $category, $alt]) {
            $file = DemoImageFactory::scene($palette, 1200, 900, $i + 2, 'gallery-' . ($i + 1) . '.jpg');
            $hash = hash_file('sha256', $file->getRealPath());
            $asset = WebsiteAsset::query()->where('website_id', $website->id)->where('content_hash', $hash)->first();

            if ($asset === null) {
                $asset = $this->uploads->store($website, $file, $alt, WebsiteAssetPurpose::Gallery);
                $this->gallery->setTitleAndCategory($website, $asset, WebsiteAssetPurpose::Gallery, $title, $category, $alt);
                $this->dirty = true;
            } else {
                $this->restoreFileIfMissing($asset, $file->getRealPath());
            }

            $galleryUids[] = $asset->uid;
        }
        if (! $website->gallery_page_enabled) {
            $website->forceFill(['gallery_page_enabled' => true])->save();
            $this->dirty = true;
        }

        $heroUid = (string) ($website->fresh()->theme['hero_asset_uid'] ?? '');
        $deployment = $context->refs['form_deployments']['Get a Quote']['website'];
        $sections = $this->sections($context, $website, $heroUid, $galleryUids, (string) $deployment->uid);

        foreach ($sections as $slug => $page) {
            $existing = $slug === '' ? $website->pages()->where('is_home', true)->first() : $website->pages()->where('slug', $slug)->first();
            $attributes = [
                'title' => $page['title'],
                'slug' => $slug === '' ? null : $slug,
                'is_home' => $slug === '',
                'sections' => $page['sections'],
                'seo_title' => $page['seo_title'],
                'meta_description' => $page['meta'],
                'noindex' => false,
            ];

            if ($existing === null) {
                $this->pages->createPage($website, $attributes);
                $this->dirty = true;
            } elseif ($this->differs($existing, $attributes)) {
                $this->pages->updatePage($website, $existing, $attributes);
                $this->dirty = true;
            }
        }

        $website = $website->fresh();

        if ($website->published_revision_id === null || $this->dirty) {
            $this->publisher->publish($website, $context->user->id);
            $website = $website->fresh();
        }

        $context->refs['website'] = $website;
        $context->refs['gallery_uids'] = $galleryUids;
        $context->note('website_pages', $website->pages()->count());
        $context->note('website_assets', $website->assets()->count());
        $context->note('website_status', (string) ($website->status->value ?? $website->status));
        $context->note('website_public_id', (string) $website->public_id);
    }

    private function assetExists(Website $website, mixed $uid): bool
    {
        if (! is_string($uid) || $uid === '') {
            return false;
        }

        $asset = WebsiteAsset::query()->where('website_id', $website->id)->where('uid', $uid)->first();

        return $asset !== null && is_file(public_path($asset->path));
    }

    /** Public images live under public/ and can vanish on a fresh deploy; put them back. */
    private function restoreFileIfMissing(WebsiteAsset $asset, string $source): void
    {
        $destination = public_path($asset->path);

        if (is_file($destination)) {
            return;
        }

        @mkdir(dirname($destination), 0755, true);
        copy($source, $destination);

        // The responsive WebP variants sit beside the original and are lost with it.
        $purpose = $asset->purpose instanceof WebsiteAssetPurpose
            ? $asset->purpose
            : (WebsiteAssetPurpose::tryFrom((string) $asset->purpose) ?? WebsiteAssetPurpose::Gallery);
        app(ImageVariants::class)->generate($asset->path, WebsiteMediaPayload::stepsFor($purpose));
    }

    /** MySQL's JSON type reorders object keys, so compare structure, never key order. */
    private function canonical(mixed $value): string
    {
        $sort = function (mixed $node) use (&$sort): mixed {
            if (! is_array($node)) {
                return $node;
            }

            $node = array_map($sort, $node);

            if (! array_is_list($node)) {
                ksort($node);
            }

            return $node;
        };

        return (string) json_encode($sort($value));
    }

    private function differs(WebsitePage $page, array $attributes): bool
    {
        return $this->canonical($page->sections) !== $this->canonical($attributes['sections'])
            || (string) $page->title !== $attributes['title']
            || (string) $page->seo_title !== (string) $attributes['seo_title']
            || (string) $page->meta_description !== (string) $attributes['meta_description']
            || (bool) $page->noindex !== $attributes['noindex'];
    }

    /**
     * @param  array<int, string>  $gallery  asset uids
     * @return array<string, array{title: string, seo_title: string, meta: string, sections: array<int, array<string, mixed>>}>
     */
    private function sections(DemoContext $context, Website $website, string $heroUid, array $gallery, string $formDeploymentUid): array
    {
        $existing = fn (string $slug) => $slug === '' ? $website->pages()->where('is_home', true)->first() : $website->pages()->where('slug', $slug)->first();
        $find = fn (?WebsitePage $page, string $type, ?string $heading = null) => collect($page?->sections ?? [])->first(
            fn ($section) => $section['type'] === $type && ($heading === null || ($section['data']['heading'] ?? null) === $heading)
        );

        $home = $existing('');
        $servicesSection = $find($home, 'services', 'Our services');
        $packagesSection = $find($home, 'services', 'Packages & products');
        $phone = BrightFrameData::PHONE;

        $faqs = [
            ['question' => 'How far in advance should we book?', 'answer' => 'Popular Saturdays in spring and fall fill several months ahead, so we suggest reserving as soon as your date and venue are confirmed.'],
            ['question' => 'Is an attendant included?', 'answer' => 'Yes. Every package includes a friendly attendant who sets up, runs the booth and keeps the line moving.'],
            ['question' => 'How do guests get their photos?', 'answer' => 'Guests share instantly by text, email or QR code, and every event gets a private online gallery afterward.'],
            ['question' => 'Can you match our event theme?', 'answer' => 'Yes. We design a custom photo template for your event and add branded overlays on the Luxe package.'],
            ['question' => 'How much space does a booth need?', 'answer' => 'Most booths need about a ten by ten foot area and a standard power outlet. We confirm the layout with your venue.'],
            ['question' => 'Do you travel outside Charlotte?', 'answer' => 'Yes. We serve Charlotte and the surrounding Carolinas, including Concord, Huntersville, Matthews, Fort Mill and Gastonia.'],
        ];

        $testimonials = ['heading' => 'Loved by Carolina event hosts', 'items' => [
            ['quote' => 'The Glam Booth was the highlight of our reception, and the attendant kept everyone laughing all night.', 'author_name' => 'Rachel G.', 'author_title' => 'Wedding client'],
            ['quote' => 'Our team loved the 360 Video Booth at the launch party. Setup was quick and the clips were ready to share instantly.', 'author_name' => 'Peter K.', 'author_title' => 'Corporate event client'],
            ['quote' => 'Easy to book, right on time and a great fit for our daughter\'s sweet sixteen.', 'author_name' => 'Grace L.', 'author_title' => 'Birthday client'],
        ]];

        $cta = ['type' => 'cta', 'data' => [
            'heading' => 'Ready to save your date?',
            'body' => 'Tell us about your event and we will send a custom quote within one business day.',
            'buttons' => [['label' => 'Get a free quote', 'url' => '/contact'], ['label' => 'Call ' . $phone, 'url' => 'tel:' . BrightFrameData::PHONE_E164]],
        ]];

        $contactDetails = ['type' => 'contact_details', 'data' => ['show_phone' => true, 'show_email' => true, 'show_address' => true]];

        $pages = [];

        $pages[''] = [
            'title' => 'Home',
            'seo_title' => 'Charlotte Photo Booth Rental | BrightFrame',
            'meta' => 'Modern photo booth rentals for Charlotte weddings, corporate events, birthdays and proms. Attendant, studio lighting and instant sharing included.',
            'sections' => array_values(array_filter([
                ['type' => 'hero', 'data' => [
                    'heading' => 'Photo booths your guests will not stop talking about',
                    'subheading' => 'Weddings, corporate events, birthdays and proms across Charlotte and the Carolinas, with a friendly attendant and instant sharing included.',
                    'background_image' => $heroUid,
                    'primary_cta' => ['label' => 'Get a free quote', 'url' => '/contact'],
                    'secondary_cta' => ['label' => 'See packages', 'url' => '/packages'],
                ]],
                ['type' => 'image_text', 'data' => [
                    'heading' => 'Modern booths, zero stress',
                    'body' => "We bring the booth, the lighting and a friendly attendant, and handle setup and teardown. Guests get instant photos and clips by text, email or QR code, and you get a private online gallery after the event.\n\nEvery booking includes unlimited captures and a custom photo template designed around your event.",
                    'image' => $gallery[0],
                    'image_position' => 'right',
                ]],
                $servicesSection,
                ['type' => 'gallery', 'data' => ['heading' => 'Recent events', 'items' => array_map(fn ($uid) => ['image' => $uid], array_slice($gallery, 0, 6))]],
                ['type' => 'testimonials', 'data' => $testimonials],
                $packagesSection,
                ['type' => 'faq', 'data' => ['heading' => 'Questions we hear a lot', 'items' => array_slice($faqs, 0, 4)]],
                $cta,
                $contactDetails,
            ])),
        ];

        foreach (['services' => ['Services', 'Photo Booth Services in Charlotte | BrightFrame', 'Classic, glam mirror, 360 video and roaming photographer booths for Charlotte events.'],
            'packages' => ['Packages', 'Photo Booth Packages and Pricing | BrightFrame', 'Transparent photo booth packages for Charlotte weddings and events, with attendant and sharing included.']] as $slug => [$title, $seo, $meta]) {
            $current = $existing($slug);
            $pages[$slug] = [
                'title' => $title, 'seo_title' => $seo, 'meta' => $meta,
                // The starter page's own sections, plus (Packages only) one closing call to action, never repeated.
                'sections' => $current !== null
                    ? array_merge(
                        array_values(array_filter($current->sections, fn ($section) => ($section['type'] ?? null) !== 'cta')),
                        $slug === 'packages' ? [$cta] : [],
                    )
                    : [],
            ];
        }

        foreach ($website->pages()->where('slug', 'like', 'service-%')->get() as $servicePage) {
            $pages[$servicePage->slug] = [
                'title' => $servicePage->title,
                'seo_title' => mb_substr($servicePage->title . ' Rental in Charlotte | BrightFrame', 0, 70),
                'meta' => mb_substr('Book the ' . $servicePage->title . ' for your Charlotte event. Attendant, studio lighting and instant sharing included.', 0, 160),
                'sections' => $servicePage->sections,
            ];
        }

        $pages['gallery'] = [
            'title' => 'Gallery', 'seo_title' => 'Photo Booth Gallery | BrightFrame', 'meta' => 'See BrightFrame photo booths at weddings, corporate events, birthdays and proms.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'Event gallery', 'subheading' => 'A look at the moments our booths have captured.']],
                ['type' => 'gallery', 'data' => ['heading' => 'Weddings, launches and celebrations', 'items' => array_map(fn ($uid) => ['image' => $uid], $gallery)]],
                $cta,
            ],
        ];

        $pages['faq'] = [
            'title' => 'FAQ', 'seo_title' => 'Photo Booth Rental FAQ | BrightFrame', 'meta' => 'Answers about booking, attendants, sharing, space needs and service areas.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'Frequently asked questions']],
                ['type' => 'faq', 'data' => ['heading' => 'Booking and event day', 'items' => $faqs]],
                $cta,
            ],
        ];

        $pages['photo-booth-about'] = [
            'title' => 'About', 'seo_title' => 'About BrightFrame Photo Booths', 'meta' => 'A Charlotte photo booth team serving weddings, corporate events, birthdays and proms across the Carolinas.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'About BrightFrame Photo Booths', 'subheading' => 'A Charlotte team that makes events more fun.']],
                ['type' => 'image_text', 'data' => [
                    'heading' => 'Our story',
                    'body' => BrightFrameData::ABOUT,
                    'image' => $gallery[3],
                    'image_position' => 'left',
                ]],
                ['type' => 'testimonials', 'data' => $testimonials],
                $cta,
                $contactDetails,
            ],
        ];

        $pages['photo-booth-contact'] = [
            'title' => 'Contact', 'seo_title' => 'Get a Photo Booth Quote | BrightFrame', 'meta' => 'Request a custom photo booth quote for your Charlotte event. We reply within one business day.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'Get your free quote', 'subheading' => 'Tell us about your event and we will reply within one business day.']],
                ['type' => 'forms_module_form', 'data' => ['heading' => 'Request a quote', 'forms_module_deployment_uid' => $formDeploymentUid]],
                $contactDetails,
            ],
        ];

        // The starter set names the contact page "photo-booth-contact"; the CTA buttons above point at /contact,
        // so the slug has to match or the buttons would 404.
        foreach (['/contact' => '/photo-booth-contact', '/packages' => '/packages'] as $from => $to) {
            $pages = $this->retarget($pages, $from, $to);
        }

        return array_filter($pages, fn (array $page) => $page['sections'] !== []);
    }

    /** @param array<string, array<string, mixed>> $pages */
    private function retarget(array $pages, string $from, string $to): array
    {
        return json_decode(str_replace('"url":"' . str_replace('/', '\/', $from) . '"', '"url":"' . str_replace('/', '\/', $to) . '"', json_encode($pages)), true);
    }
}
