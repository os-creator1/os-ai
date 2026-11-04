<?php

namespace App\Library\Migration\Toronto;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Forms\FormManager;
use App\Library\Website\Design\WebsiteLookService;
use App\Library\Website\Gallery\WebsiteGalleryManager;
use App\Library\Website\Seo\WebsiteRedirectManager;
use App\Library\Website\WebsiteAssetUploadService;
use App\Library\Website\WebsiteDraftPageService;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\Business;
use App\Models\BusinessLocation;
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
use Throwable;

/**
 * Builds the Toronto Photo Booth website with MotionGrove's existing site machinery (starter shell from the
 * photo_booth_modern template, page service, asset store, gallery manager, Forms module, publisher): no AI, no metered
 * API, no new platform. All copy and every image role live in docs/migrations/toronto-photo-booth/website-content.json,
 * captured from the live emphotobooth.site; this class only places them.
 *
 *  - No prices (the source brochure's prices cannot be tied to named packages), no invented content.
 *  - Testimonials are the three real reviews on the source site.
 *  - Images are resolved by SHA-256 from --assets, stored in MotionGrove media; nothing hot-links GHL.
 *  - The availability form is a real Forms-module form deployed to the website. It is published to the platform preview
 *    path only: no custom domain is attached and no DNS is touched.
 *  - Idempotent: an existing website for the Business is reused and pages are updated in place.
 */
final class TorontoWebsiteBuilder
{
    public const TEMPLATE_KEY = 'photo_booth_modern';

    public const FORM_NAME = 'Check Availability';

    private const EVENT_TYPES = ['Wedding', 'Corporate Party', 'Birthday Party', 'Party', 'Brand Activation', 'School Dance', 'Holiday Party', 'Event'];

    private const CONSENT_TRANSACTIONAL = 'By checking this box, I consent to receive transactional SMS/text messages and emails from The Photo Booth Company of Toronto regarding my inquiry, event details, and service updates. Message frequency may vary. Msg & data rates may apply. Reply HELP for help or STOP to unsubscribe.';

    private const CONSENT_MARKETING = 'By checking this box, I agree to receive marketing and promotional messages from The Photo Booth Company of Toronto, including special offers, discounts, and new product updates. Message frequency may vary. Msg & data rates may apply. Consent is not required for purchase. Reply STOP to unsubscribe.';

    /** @var array<string, WebsiteAsset> */
    private array $assets = [];

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
        private readonly WebsiteRedirectManager $redirects,
    ) {
    }

    /** @return array{website: Website, report: array<string, int|string>, missing: list<string>} */
    public function build(Business $business, BusinessLocation $location, int $actorUserId, string $assetsDir, string $contentPath): array
    {
        $content = json_decode((string) file_get_contents($contentPath), true) ?: throw new RuntimeException('Bad website content file.');
        $files = $this->index($assetsDir);
        $missing = [];

        $this->catalogAndServices($business, $content['catalog'] ?? [], $actorUserId);

        $website = Website::query()->where('business_id', $business->id)->first();
        if ($website === null) {
            $template = WebsiteTemplate::query()->where('key', self::TEMPLATE_KEY)->first()
                ?? WebsiteTemplate::create(collect(WebsiteTemplateSeeder::templates())->firstWhere('key', self::TEMPLATE_KEY));
            $website = $this->starter->createShellFromTemplate($business, $template)->fresh();
            $this->look->setBrandColor($website, $content['brand_color']);
            $website = $website->fresh();
        }

        foreach ($content['assets'] as $role => $entry) {
            $path = $files[$entry['sha256']] ?? null;
            if ($path === null) {
                $missing[] = $role;

                continue;
            }
            $this->assets[$role] = $this->storeAsset($website, $role, $path, $entry);
        }

        $website->refresh();
        if (isset($this->assets['logo']) && ! isset($website->theme['logo_asset_uid'])) {
            $this->look->setLogo($website, $this->upload($files[$content['assets']['logo']['sha256']], 'logo'), $content['assets']['logo']['alt']);
        }
        if (isset($this->assets['hero']) && ! isset($website->fresh()->theme['hero_asset_uid'])) {
            $this->look->setHero($website, $this->upload($files[$content['assets']['hero']['sha256']], 'hero'), $content['assets']['hero']['alt']);
        }
        $website = $website->fresh();
        $this->look->setLegalLinks($website, 'https://emphotobooth.site/Privacy-Policy', 'https://emphotobooth.site/Terms-Conditions');
        $website = $website->fresh();

        $deployment = $this->quoteForm($business, $location, $actorUserId);

        foreach ($content['pages'] as $slug => $page) {
            $slug = (string) $slug;
            $page['sections'] = $this->resolve($page['sections'], (string) $deployment->uid);
            $existing = $slug === '' ? $website->pages()->where('is_home', true)->first() : $website->pages()->where('slug', $slug)->first();
            $attributes = $page + ['slug' => $slug === '' ? null : $slug, 'is_home' => $slug === '', 'noindex' => false];
            $existing === null
                ? $this->pages->createPage($website, $attributes)
                : $this->pages->updatePage($website, $existing, $attributes);
        }

        $website = $website->fresh();
        $redirects = 'set';
        try {
            // Old GHL funnel paths with no one-for-one page.
            $this->redirects->set($website, [
                'home' => '', 'brochure' => 'packages', 'thank-you' => 'check-availability', 'photo-booth-rental' => '',
                'toronto' => 'service-areas', 'north-york' => 'service-areas', 'mississauga' => 'service-areas', 'vaughan' => 'service-areas',
                'etobicoke' => 'service-areas', 'markham' => 'service-areas', 'scarborough' => 'service-areas',
            ]);
        } catch (Throwable $e) {
            $redirects = 'skipped: '.$e->getMessage();
        }
        $website = $website->fresh();

        // Never publish a site whose required images are missing: report instead.
        if ($missing === []) {
            $this->publisher->publish($website, $actorUserId);
            $website = $website->fresh();
        }

        return [
            'website' => $website,
            'report' => [
                'pages' => $website->pages()->count(), 'assets_stored' => $website->assets()->count(), 'assets_expected' => count($content['assets']),
                'status' => (string) ($website->status->value ?? $website->status), 'public_id' => (string) $website->public_id,
                'published' => $missing === [] ? 'yes (platform preview path only; no custom domain, no DNS)' : 'NO: required images missing',
                'redirects' => $redirects, 'form_deployment_uid' => (string) $deployment->uid,
            ],
            'missing' => $missing,
        ];
    }

    /** Replaces "@asset:role" and "@form" placeholders anywhere in the section data. */
    private function resolve(mixed $node, string $deploymentUid): mixed
    {
        if (is_array($node)) {
            $out = [];
            foreach ($node as $k => $v) {
                $r = $this->resolve($v, $deploymentUid);
                if ($r !== null || ! (is_string($v) && str_starts_with($v, '@'))) {
                    $out[$k] = $r;
                }
            }

            return $out;
        }
        if ($node === '@form') {
            return $deploymentUid;
        }
        if (is_string($node) && str_starts_with($node, '@asset:')) {
            return $this->assets[substr($node, 7)]->uid ?? null;
        }

        return $node;
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

    /** @param array<string, mixed> $entry */
    private function storeAsset(Website $website, string $role, string $path, array $entry): WebsiteAsset
    {
        $hash = hash_file('sha256', $path);
        $existing = WebsiteAsset::query()->where('website_id', $website->id)->where('content_hash', $hash)->first();
        if ($existing !== null) {
            return $existing;
        }

        $isGallery = ($entry['purpose'] ?? 'gallery') === 'gallery';
        $asset = $this->uploads->store($website, $this->upload($path, $role), $entry['alt'], $isGallery ? WebsiteAssetPurpose::Gallery : WebsiteAssetPurpose::Hero);
        if ($isGallery) {
            $this->gallery->setTitleAndCategory($website, $asset, WebsiteAssetPurpose::Gallery, (string) ($entry['title'] ?? ucfirst(str_replace('_', ' ', $role))), (string) ($entry['category'] ?? 'Events'), $entry['alt']);
        }

        return $asset;
    }

    /** @param list<array{key: string, type: string, name: string, description: string}> $items */
    private function catalogAndServices(Business $business, array $items, int $actorUserId): void
    {
        $this->services->syncForBusiness($business, [[
            'name' => 'Photo booth rental',
            'description' => 'Luxury photo booth rental for weddings, corporate events, birthdays, school dances and private celebrations in Toronto and the GTA.',
            'sort_order' => 0, 'is_primary' => true,
        ]]);

        foreach ($items as $item) {
            $attributes = ['type' => $item['type'], 'name' => $item['name'], 'description' => $item['description'], 'price_minor' => null, 'currency_code' => null, 'featured' => false, 'source_questionnaire_item_key' => $item['key']];
            $existing = $this->catalog->findBySourceKey($business, $item['key']);
            $existing === null ? $this->catalog->create($business, $attributes, $actorUserId) : $this->catalog->update($business, $existing, $attributes);
        }
    }

    private function quoteForm(Business $business, BusinessLocation $location, int $actorUserId): \App\Models\FormDeployment
    {
        $form = Form::query()->where('business_id', $business->id)->where('name', self::FORM_NAME)->first();

        if ($form === null) {
            $defs = CustomFieldDefinition::query()->where('business_id', $business->id)->get()->keyBy(fn ($d) => mb_strtolower((string) $d->label));
            $pipeline = CrmPipeline::query()->where('business_id', $business->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->first();

            $fields = [
                ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                ['label' => 'Phone', 'type' => 'phone', 'required' => true],
                ['label' => 'Email', 'type' => 'email', 'required' => true],
                ['label' => 'Event date', 'type' => 'date', 'required' => true] + (isset($defs['event date']) ? ['custom_field_uid' => $defs['event date']->uid] : []),
                ['label' => 'What type of event are you planning?', 'type' => 'multi_select', 'required' => true, 'options' => implode("\n", self::EVENT_TYPES)] + (isset($defs['event type']) ? ['custom_field_uid' => $defs['event type']->uid] : []),
                ['label' => 'Where is your event (city or venue)?', 'type' => 'text', 'required' => false],
                ['label' => 'Anything else we should know?', 'type' => 'textarea', 'required' => false],
                // Both consents are optional and recorded separately; neither is required to inquire.
                ['label' => self::CONSENT_TRANSACTIONAL, 'type' => 'consent_transactional', 'required' => false],
                ['label' => self::CONSENT_MARKETING, 'type' => 'consent_marketing', 'required' => false],
            ];

            $form = $this->forms->create($business, [
                'name' => self::FORM_NAME,
                'intro' => 'Fill in the short form below to check availability for your event date!',
                'submit_label' => 'Check availability now',
                'success_message' => "Thank you. We'll confirm our availability shortly! One of our photo booth planners will contact you to discuss the details for your event.",
                'create_opportunity' => $pipeline !== null,
                'opportunity_pipeline_id' => $pipeline?->id,
                'fields' => $fields,
            ], $actorUserId);
        }

        $this->forms->activate($business, $form);
        $this->forms->setDeployment($business, $form, $location, true, FormDeploymentSource::DirectLink);

        return $this->forms->setDeployment($business, $form, $location, true, FormDeploymentSource::Website);
    }
}
