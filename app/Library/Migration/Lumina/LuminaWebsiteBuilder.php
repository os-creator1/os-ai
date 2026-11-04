<?php

namespace App\Library\Migration\Lumina;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Forms\FormManager;
use App\Library\Website\Design\WebsiteLookService;
use App\Library\Website\Gallery\WebsiteGalleryManager;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\CrmPipeline;
use App\Models\CustomFieldDefinition;
use App\Models\Form;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteTemplate;
use App\Repositories\Contracts\BusinessServiceRepository;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Builds the Lumina Aura website with MotionGrove's existing site machinery (starter shell from the
 * photo_booth_modern template, page service, asset store, gallery manager, Forms module, publisher) — no AI,
 * no metered API. Content is ONLY what the live site / GHL account states:
 *
 *  - ONE primary service (the 360° video booth) plus its genuine add-ons; no invented booths or packages.
 *  - NO prices: the source pricing is contradictory, so every price surface is "request a quote / check
 *    availability" until the owner confirms. Packages are not generated.
 *  - NO testimonials: none exist in the source.
 *  - Photos are the customer's own originals, resolved by SHA-256 from the supplied asset directory and stored in
 *    MotionGrove-managed media; nothing hot-links GHL.
 *  - The quote form is a real Forms-module form deployed to the website: it creates/matches the Contact, records
 *    the transactional/marketing consent answers separately, and opens an opportunity in Inquiries / New Lead.
 */
final class LuminaWebsiteBuilder
{
    public const TEMPLATE_KEY = 'photo_booth_modern';

    public const BRAND_COLOR = '#9c6247';

    public const FORM_NAME = 'Check Availability';

    private const EVENT_TYPES = ['Wedding', 'Birthday Party', 'Corporate Party', 'Brand Activation', 'Event', 'Party', 'Graduation Party', 'Fundraiser', 'Trade Show', 'Baby Shower'];

    private const CONSENT_TRANSACTIONAL = 'By checking this box, I consent to receive transactional SMS/text messages and emails from Lumina Aura Events regarding my inquiry, event details, and service updates. Message frequency may vary. Msg & data rates may apply. Reply HELP for help or STOP to unsubscribe.';

    private const CONSENT_MARKETING = 'By checking this box, I agree to receive marketing and promotional messages from Lumina Aura Events, including special offers, discounts, and new product updates. Message frequency may vary. Msg & data rates may apply. Consent is not required for purchase. Reply STOP to unsubscribe.';

    /** @var array<string, WebsiteAsset> */
    private array $assets = [];

    /** @var array<string, string> */
    private array $videoTitles = [];

    public function __construct(
        private readonly WebsiteStarterDraftService $starter,
        private readonly WebsiteDraftPageService $pages,
        private readonly WebsiteLookService $look,
        private readonly WebsiteAssetUploadService $uploads,
        private readonly WebsiteGalleryManager $gallery,
        private readonly WebsitePublisher $publisher,
        private readonly FormManager $forms,
        private readonly CatalogItemManager $catalog,
        private readonly BusinessServiceRepository $services,
        private readonly \App\Library\Website\Seo\WebsiteRedirectManager $redirects,
    ) {
    }

    /**
     * @param  string  $assetsDir  directory holding the downloaded originals (any depth)
     * @param  string  $manifestPath  docs/migrations/lumina-aura/website-manifest.json
     * @param  string  $legalDir  docs/migrations/lumina-aura/source
     * @return array{website: Website, report: array<string, int|string>, missing: list<string>}
     */
    public function build(Business $business, BusinessLocation $location, int $actorUserId, string $assetsDir, string $manifestPath, string $legalDir): array
    {
        $manifest = json_decode((string) file_get_contents($manifestPath), true)['assets'] ?? throw new RuntimeException('Bad manifest.');
        $files = $this->index($assetsDir);
        $missing = [];

        $this->catalogAndServices($business, $actorUserId);

        $template = WebsiteTemplate::query()->where('key', self::TEMPLATE_KEY)->first()
            ?? WebsiteTemplate::create(collect(WebsiteTemplateSeeder::templates())->firstWhere('key', self::TEMPLATE_KEY));
        $website = $this->starter->createShellFromTemplate($business, $template)->fresh();

        $this->look->setBrandColor($website, self::BRAND_COLOR);
        $website = $website->fresh();

        foreach ($manifest as $role => $entry) {
            $path = $files[$entry['sha256']] ?? null;
            if ($path === null) {
                $missing[] = $role;

                continue;
            }
            $this->assets[$role] = $this->storeAsset($website, $role, $path, $entry['alt']);
        }

        // The customer's own MP4s, stored in the same persisted directory as the images.
        foreach ((json_decode((string) file_get_contents($manifestPath), true)['videos'] ?? []) as $role => $entry) {
            $path = $files[$entry['sha256']] ?? null;
            if ($path === null) {
                $missing[] = $role;

                continue;
            }
            $this->assets[$role] = $this->uploads->storeVideo($website, $this->upload($path, $role), $entry['alt']);
            $this->videoTitles[$role] = $entry['title'];
        }

        $heroUid = $this->uid('event_7') ?? $this->uid('booth_1');
        $website->refresh();
        if (isset($this->assets['logo']) && ! isset($website->theme['logo_asset_uid'])) {
            $this->look->setLogo($website, $this->upload($files[$manifest['logo']['sha256']], 'logo'), $manifest['logo']['alt']);
        }
        if ($heroUid !== null && ! isset($website->fresh()->theme['hero_asset_uid'])) {
            $role = isset($this->assets['event_7']) ? 'event_7' : 'booth_1';
            $this->look->setHero($website, $this->upload($files[$manifest[$role]['sha256']], 'hero'), $manifest[$role]['alt']);
        }
        $website = $website->fresh();
        $this->look->setLegalLinks($website, 'https://luminaauraevents.com/privacy-policy', 'https://luminaauraevents.com/terms-of-service');
        $website = $website->fresh();

        $deployment = $this->quoteForm($business, $location, $actorUserId);
        $hero = (string) ($website->theme['hero_asset_uid'] ?? $heroUid);

        foreach ($this->pageSet($business, $hero, (string) $deployment->uid, $legalDir) as $slug => $page) {
            $existing = $slug === '' ? $website->pages()->where('is_home', true)->first() : $website->pages()->where('slug', $slug)->first();
            $attributes = $page + ['slug' => $slug === '' ? null : $slug, 'is_home' => $slug === '', 'noindex' => $page['noindex'] ?? false];
            $existing === null
                ? $this->pages->createPage($website, $attributes)
                : $this->pages->updatePage($website, $existing, $attributes);
        }

        $website = $website->fresh();
        // The old GHL addresses that no longer match a page one-for-one (matching paths and capitals are handled automatically).
        $this->redirects->set($website, ['brochure' => '', 'thank-you' => 'check-availability', 'terms-conditions' => 'terms-and-conditions']);
        $website = $website->fresh();
        $this->publisher->publish($website, $actorUserId);
        $website = $website->fresh();

        return [
            'website' => $website,
            'report' => [
                'pages' => $website->pages()->count(), 'assets' => $website->assets()->count(),
                'status' => (string) ($website->status->value ?? $website->status), 'public_id' => (string) $website->public_id,
                'form_deployment_uid' => (string) $deployment->uid,
            ],
            'missing' => $missing,
        ];
    }

    /** @return array<string, string> sha256 => path */
    private function index(string $dir): array
    {
        $out = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) {
                $out[hash_file('sha256', $file->getPathname())] = $file->getPathname();
            }
        }

        return $out;
    }

    private function upload(string $path, string $label): UploadedFile
    {
        return new UploadedFile($path, $label.'-'.basename($path), mime_content_type($path) ?: 'image/jpeg', null, true);
    }

    private function storeAsset(Website $website, string $role, string $path, string $alt): WebsiteAsset
    {
        $hash = hash_file('sha256', $path);
        $existing = WebsiteAsset::query()->where('website_id', $website->id)->where('content_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $isGallery = str_starts_with($role, 'event_') || str_starts_with($role, 'ivy_') || str_starts_with($role, 'booth_') || str_starts_with($role, 'inflatable_') || $role === 'vinyl';
        $asset = $this->uploads->store($website, $this->upload($path, $role), $alt, $isGallery ? WebsiteAssetPurpose::Gallery : WebsiteAssetPurpose::Hero);
        if ($isGallery) {
            $this->gallery->setTitleAndCategory($website, $asset, WebsiteAssetPurpose::Gallery, ucfirst(str_replace('_', ' ', $role)), str_starts_with($role, 'event_') ? 'Events' : 'Equipment', $alt);
        }

        return $asset;
    }

    private function uid(string $role): ?string
    {
        return isset($this->assets[$role]) ? (string) $this->assets[$role]->uid : null;
    }

    private function catalogAndServices(Business $business, int $actorUserId): void
    {
        $this->services->syncForBusiness($business, [[
            'name' => '360 Video Booth',
            'description' => 'A rotating-camera 360° video booth: guests step on the platform and get smooth, cinematic videos with custom overlays, music and instant sharing.',
            'sort_order' => 0, 'is_primary' => true,
        ]]);

        foreach ([
            ['custom_vinyl', 'Custom vinyl', 'Custom vinyl on the 360 platform, designed to match your brand or event style.'],
            ['inflatable_enclosure', 'Inflatable 360 enclosure', 'A stylish inflatable enclosure (10 x 10 x 9 ft) that enhances lighting and creates an immersive 360° video experience. Includes an LED strip with 16 colors to choose from.'],
            ['branding_filters', 'Branding & custom filters', 'Branding and custom filters for the content your guests create, available for an additional cost.'],
            ['ivy_backdrops', 'Ivy backdrops', 'Ivy backdrops with à la carte sizing and decoration options, 6 feet tall and up to 8 feet long (3 and 5 foot sections available). Custom neon signage and fairy lighting are also available.'],
        ] as [$key, $name, $description]) {
            $sourceKey = 'lumina_'.$key;
            $attributes = ['type' => 'product', 'name' => $name, 'description' => $description, 'price_minor' => null, 'currency_code' => null, 'featured' => false, 'source_questionnaire_item_key' => $sourceKey];
            $item = $this->catalog->findBySourceKey($business, $sourceKey);
            $item === null ? $this->catalog->create($business, $attributes, $actorUserId) : $this->catalog->update($business, $item, $attributes);
        }
    }

    private function quoteForm(Business $business, BusinessLocation $location, int $actorUserId): \App\Models\FormDeployment
    {
        $form = Form::query()->where('business_id', $business->id)->where('name', self::FORM_NAME)->first();

        if ($form === null) {
            $defs = CustomFieldDefinition::query()->where('business_id', $business->id)->get()->keyBy(fn ($d) => mb_strtolower((string) $d->label));
            $pipeline = CrmPipeline::query()->where('business_id', $business->id)->where('name', 'Inquiries')->firstOrFail();

            $form = $this->forms->create($business, [
                'name' => self::FORM_NAME,
                'intro' => 'Fill in the short form below to check availability for your event date!',
                'submit_label' => 'Check availability now',
                'success_message' => "Thank you. We'll confirm our availability shortly! One of our expert photo booth planners will contact you shortly to discuss the details for your event.",
                'create_opportunity' => true,
                'opportunity_pipeline_id' => $pipeline->id,
                'fields' => [
                    ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                    ['label' => 'Phone', 'type' => 'phone', 'required' => true],
                    ['label' => 'Email', 'type' => 'email', 'required' => true],
                    ['label' => 'Event date', 'type' => 'date', 'required' => true, 'custom_field_uid' => $defs['event date']->uid],
                    ['label' => 'What type of event are you planning?', 'type' => 'multi_select', 'required' => true, 'options' => implode("\n", self::EVENT_TYPES), 'custom_field_uid' => $defs['event type']->uid],
                    ['label' => 'Anything else we should know?', 'type' => 'textarea', 'required' => false],
                    // Both consents are optional and recorded separately; neither is required to inquire.
                    ['label' => self::CONSENT_TRANSACTIONAL, 'type' => 'consent_transactional', 'required' => false],
                    ['label' => self::CONSENT_MARKETING, 'type' => 'consent_marketing', 'required' => false],
                ],
            ], $actorUserId);
        }

        $this->forms->activate($business, $form);
        $this->forms->setDeployment($business, $form, $location, true, FormDeploymentSource::DirectLink);

        return $this->forms->setDeployment($business, $form, $location, true, FormDeploymentSource::Website);
    }

    /** @return array<string, array<string, mixed>> */
    private function pageSet(Business $business, string $hero, string $deploymentUid, string $legalDir): array
    {
        $g = fn (array $roles) => array_values(array_filter(array_map(fn ($r) => $this->uid($r), $roles)));
        $phone = '+1 720-502-8306';
        $quote = ['label' => 'Check availability', 'url' => '/check-availability'];
        $cta = ['type' => 'cta', 'data' => [
            'heading' => 'Book now!',
            'body' => 'Make your event unforgettable with our 360° video booth. Check availability today and let your guests create fun, high-quality videos they can instantly share.',
            'buttons' => [$quote, ['label' => 'Call '.$phone, 'url' => 'tel:+17205028306']],
        ]];
        $contact = ['type' => 'contact_details', 'data' => ['show_phone' => true, 'show_email' => true, 'show_address' => false]];
        $faq = ['type' => 'faq', 'data' => ['heading' => 'FAQs', 'items' => [
            ['question' => 'How many videos can I have during my event?', 'answer' => 'Generally patrons take 1-3 videos before the next person waiting. If guests are paying to use the booth, 3 videos minimum (slomo, boomerang, classic).'],
            ['question' => 'How long does it take to set up the booth?', 'answer' => 'Allow an hour for set up and 1/2 hour for pack down.'],
            ['question' => 'Will there be someone to help us operate the booth?', 'answer' => 'Depends on the package. Clients can designate their own members for a quick five-minute training on the machine controls.'],
            ['question' => 'Is the set up time included?', 'answer' => 'The booth is set up an hour before the run time starts and is all included in the cost. If you book a 2 hour package, that is 1 hour for setup and 2 hours of run time.'],
            ['question' => 'Can we supply our own backdrop?', 'answer' => 'Definitely. Choose a non reflective surface if possible. Good idea to brainstorm with your stylist as well.'],
            ['question' => 'What space do you need?', 'answer' => 'We need at least 10 by 10 feet of space and a level ground. Please note that our backdrop stands are 8 feet tall.'],
        ]]];

        $videos = array_values(array_filter(array_map(
            fn (string $r) => $this->uid($r) === null ? null : ['video' => $this->uid($r), 'title' => $this->videoTitles[$r] ?? null],
            ['video_1', 'video_2', 'video_3'],
        )));
        $videoSection = $videos === [] ? null : ['type' => 'video', 'data' => ['heading' => 'See the 360 booth in action', 'items' => $videos]];

        $pages = [];

        $pages[''] = [
            'title' => 'Home',
            'seo_title' => '360 Video Booth Rental in Denver | Lumina Aura Events',
            'meta_description' => 'Fun 360° video booth rentals for weddings, corporate events, birthdays and brand activations in the Denver metro and Colorado Springs.',
            'sections' => array_values(array_filter([
                ['type' => 'hero', 'data' => array_filter([
                    'heading' => 'Lights, Camera, Memories!',
                    'subheading' => 'Modern & fun 360° video booth experiences in Denver and Colorado Springs.',
                    'background_image' => $hero ?: null,
                    'primary_cta' => $quote,
                    'secondary_cta' => ['label' => 'See the 360 booth', 'url' => '/360-video-booth'],
                ])],
                ['type' => 'image_text', 'data' => [
                    'heading' => '360 Video Booth',
                    'body' => "Bring your event to life with our 360° video booth experience. Guests step onto the platform while a rotating camera captures smooth, cinematic videos from every angle, creating share-worthy content in seconds. It's interactive, fun, and instantly turns any moment into a highlight.\n\nPerfect for weddings, corporate events, birthdays, and brand activations, the 360 booth delivers high-quality videos with custom overlays, music, and instant sharing. It's more than just a booth — it's an experience your guests will remember long after the event ends.",
                    'image' => $this->uid('booth_1'), 'image_position' => 'right',
                ]],
                ['type' => 'services', 'data' => ['heading' => 'What type of event are you planning?', 'items' => [
                    ['name' => 'Any party', 'description' => 'Fun, shareable 360° videos for any celebration.'],
                    ['name' => 'Wedding', 'description' => 'Elegant 360° videos for your special day.'],
                    ['name' => 'Corporate event', 'description' => 'Professional 360° videos that elevate your brand.'],
                ]]],
                ['type' => 'services', 'data' => ['heading' => 'Add-ons & backdrops', 'items' => [
                    ['name' => 'Custom vinyl', 'description' => 'Make your booth stand out with custom vinyl designed to match your brand or event style.', 'price_label' => 'Request a quote', 'image' => $this->uid('vinyl')],
                    ['name' => 'Inflatable 360 enclosure', 'description' => 'A stylish inflatable enclosure that enhances lighting and creates a clean, immersive 360° video experience.', 'price_label' => 'Request a quote', 'image' => $this->uid('inflatable_pink')],
                    ['name' => 'Ivy backdrops', 'description' => 'À la carte sizing and decoration options, with custom neon signage and fairy lighting available.', 'price_label' => 'Request a quote', 'image' => $this->uid('ivy_1')],
                ]]],
                ['type' => 'gallery', 'data' => ['heading' => 'See some of our favorite events', 'items' => array_map(fn ($u) => ['image' => $u], $g(['event_1', 'event_2', 'event_3', 'event_4', 'event_5', 'event_6']))]],
                $videoSection,
                ['type' => 'services', 'data' => ['heading' => 'What makes us different', 'items' => [
                    ['name' => 'Branding', 'description' => 'Branding & custom filters for created content for guests available for additional cost.'],
                    ['name' => 'Customization', 'description' => 'Arches & themed backdrops.'],
                    ['name' => "Let's talk!", 'description' => 'Open to sponsorship and social media collaborations.'],
                ]]],
                ['type' => 'text', 'data' => ['heading' => 'Serving Denver Metro and Colorado Springs', 'body' => 'Lumina Aura Events is based in Thornton, Colorado and brings the 360 video booth to events across the Denver metro area and Colorado Springs. Check availability to confirm your date and location.']],
                $faq,
                $cta,
                $contact,
            ])),
        ];

        $pages['360-video-booth'] = [
            'title' => '360 Video Booth',
            'seo_title' => '360 Video Booth Rental | Lumina Aura Events',
            'meta_description' => 'Rent a 360° video booth in Denver or Colorado Springs: rotating camera, custom overlays, music and instant sharing for weddings, corporate events and parties.',
            'sections' => array_values(array_filter([
                ['type' => 'hero', 'data' => array_filter(['heading' => '360 Video Booth', 'subheading' => 'Cinematic 360° videos your guests can share instantly.', 'background_image' => $hero ?: null, 'primary_cta' => $quote])],
                ['type' => 'image_text', 'data' => ['heading' => 'How it works', 'body' => "Guests step onto the platform while a rotating camera captures smooth, cinematic videos from every angle. Expect 1-3 videos per guest before the next person steps up, with custom overlays, music and instant sharing.\n\nSetup takes about an hour before your run time and pack-down about half an hour. We need at least a 10 by 10 foot level area.", 'image' => $this->uid('booth_2'), 'image_position' => 'left']],
                $videoSection,
                ['type' => 'image_text', 'data' => ['heading' => 'Custom vinyl & branding', 'body' => 'Make your booth stand out with custom vinyl designed to match your brand or event style. Branding and custom filters for the content your guests create are available for an additional cost.', 'image' => $this->uid('vinyl'), 'image_position' => 'right']],
                $cta,
            ])),
        ];

        $pages['backdrops'] = [
            'title' => 'Backdrops & add-ons',
            'seo_title' => 'Ivy Backdrops & Inflatable 360 Enclosure | Lumina Aura Events',
            'meta_description' => 'Premium ivy backdrops, neon signage, fairy lighting and an inflatable 10x10x9 ft 360 booth enclosure with 16-color LED lighting.',
            'sections' => array_values(array_filter([
                ['type' => 'hero', 'data' => array_filter(['heading' => 'Backdrop collection', 'subheading' => 'Fill in the short form to check availability for your event date.', 'primary_cta' => $quote])],
                ['type' => 'image_text', 'data' => ['heading' => 'Premium ivy backdrops', 'body' => "Ivy backdrops with à la carte sizing and decoration options. Custom neon signage and fairy lighting are also available.\n\nThese backdrops are 6 feet tall and can be up to 8 feet long. However, smaller sections of 3 or 5 feet are also available.", 'image' => $this->uid('ivy_3'), 'image_position' => 'right']],
                ['type' => 'gallery', 'data' => ['heading' => 'Ivy backdrops', 'items' => array_map(fn ($u) => ['image' => $u], $g(['ivy_1', 'ivy_2', 'ivy_3']))]],
                ['type' => 'image_text', 'data' => ['heading' => 'Inflatable booth enclosure', 'body' => 'Enhance your video\'s vibes by renting the inflatable photo booth enclosure. It measures 10 x 10 x 9 ft and accommodates the 360 booth. Setup is quick and easy and includes an LED strip with 16 different colors to choose from.', 'image' => $this->uid('inflatable_grass'), 'image_position' => 'left']],
                ['type' => 'gallery', 'data' => ['heading' => 'Inflatable enclosure', 'items' => array_map(fn ($u) => ['image' => $u], $g(['inflatable_pink', 'inflatable_grass', 'inflatable_diagram']))]],
                $cta,
            ])),
        ];

        $pages['gallery'] = [
            'title' => 'Gallery',
            'seo_title' => 'Event Gallery | Lumina Aura Events',
            'meta_description' => 'See some of our favorite events: weddings, parties and corporate celebrations captured with the Lumina Aura 360 video booth.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'Event gallery', 'subheading' => 'See some of our favorite events']],
                ['type' => 'gallery', 'data' => ['heading' => 'Favorite events', 'items' => array_map(fn ($u) => ['image' => $u], $g(['event_1', 'event_2', 'event_3', 'event_4', 'event_5', 'event_6', 'event_7', 'event_8']))]],
                $cta,
            ],
        ];

        $pages['faq'] = [
            'title' => 'FAQ',
            'seo_title' => '360 Video Booth FAQ | Lumina Aura Events',
            'meta_description' => 'Answers about 360 video booth setup time, space needs, operators, backdrops and how many videos guests can take.',
            'sections' => [['type' => 'hero', 'data' => ['heading' => 'Frequently asked questions']], $faq, $cta],
        ];

        $pages['check-availability'] = [
            'title' => 'Check availability',
            'seo_title' => 'Check Availability & Get a Quote | Lumina Aura Events',
            'meta_description' => 'Check availability for your event date and get a custom quote for a 360 video booth in Denver or Colorado Springs.',
            'sections' => [
                ['type' => 'hero', 'data' => ['heading' => 'Check availability & get a quote', 'subheading' => "We'll get in touch as soon as possible with a custom quote that covers all of your needs."]],
                ['type' => 'forms_module_form', 'data' => ['heading' => 'Check availability', 'forms_module_deployment_uid' => $deploymentUid]],
                $contact,
            ],
        ];

        foreach (['privacy-policy' => ['Privacy-Policy', 'Privacy Policy'], 'terms-of-service' => ['terms-of-service', 'Terms of Service'], 'terms-and-conditions' => ['Terms-Conditions', 'Terms & Conditions']] as $slug => [$file, $title]) {
            $pages[$slug] = [
                'title' => $title,
                'seo_title' => $title.' | Lumina Aura Events',
                'meta_description' => null,
                'sections' => $this->legalSections($legalDir.'/'.$file.'.txt', $title),
            ];
        }

        return $pages;
    }

    /** The customer's existing legal text, verbatim, as paragraphs (<=4800 chars per section). */
    private function legalSections(string $file, string $title): array
    {
        $raw = (string) file_get_contents($file);
        $raw = preg_replace('/^.*?Check Availability \| /s', '', $raw, 1);
        $raw = preg_replace('/ \| HOME \|.*$/s', '', $raw);
        $blocks = array_values(array_filter(array_map('trim', explode(' | ', $raw))));
        if ($blocks !== [] && $blocks[0] === $title) {
            array_shift($blocks);
        }

        $sections = [['type' => 'hero', 'data' => ['heading' => $title]]];
        $buffer = '';
        foreach ($blocks as $block) {
            $block = mb_substr($block, 0, 4700);
            if (mb_strlen($buffer) + mb_strlen($block) + 2 > 4800) {
                $sections[] = ['type' => 'text', 'data' => ['body' => $buffer]];
                $buffer = '';
            }
            $buffer .= ($buffer === '' ? '' : "\n\n").$block;
        }
        if ($buffer !== '') {
            $sections[] = ['type' => 'text', 'data' => ['body' => $buffer]];
        }

        return $sections;
    }
}
