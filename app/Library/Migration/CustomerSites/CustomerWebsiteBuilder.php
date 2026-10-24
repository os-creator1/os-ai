<?php

namespace App\Library\Migration\CustomerSites;

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
use App\Models\Contacts;
use App\Models\CrmOpportunity;
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
 * Builds ONE existing customer's photo booth website from a reviewed content file
 * (docs/migrations/customer-websites/<key>/website-content.json) with MotionGrove's own website machinery: the starter shell of
 * the content's template, page service, asset store, gallery manager, Forms module and publisher. No AI, no metered API, no
 * Business Pack / niche-blueprint installation, no new platform.
 *
 *  - The Business is addressed by id AND must match the content file's workspace id and name: a wrong target aborts.
 *  - It writes only Website-side rows (website, pages, assets, a Form + its website deployment, catalog items, service
 *    list). It never edits a contact, opportunity, pipeline, location, plan assignment or billing record, and it sends
 *    nothing (the command fakes mail/queue/notifications and refuses outbound HTTP).
 *  - Every image is a binary resolved by SHA-256 from --assets and stored in MotionGrove media; nothing hot-links.
 *  - Brand colour AND secondary colour come from the content file.
 *  - Idempotent: an existing website for the Business is reused and its pages are updated in place; assets are deduped by hash.
 *  - A site is published (to the platform preview path only: no custom domain, no DNS) only when every asset resolved and the
 *    content file lists no blocker. Otherwise it stays an unpublished draft and the gaps are reported.
 */
final class CustomerWebsiteBuilder
{
    private const CONSENT_TRANSACTIONAL = 'By checking this box, I consent to receive transactional SMS/text messages and emails from %s regarding my inquiry, event details, and service updates. Message frequency may vary. Msg & data rates may apply. Reply HELP for help or STOP to unsubscribe.';

    private const CONSENT_MARKETING = 'By checking this box, I agree to receive marketing and promotional messages from %s, including special offers, discounts, and new product updates. Message frequency may vary. Msg & data rates may apply. Consent is not required for purchase. Reply STOP to unsubscribe.';

    public const FORM_NAME = 'Check Availability';

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
    ) {
    }

    /** @return array<string, mixed> */
    public function load(string $contentPath): array
    {
        $content = is_file($contentPath) ? json_decode((string) file_get_contents($contentPath), true) : null;
        foreach (['business', 'template_key', 'brand_color', 'secondary_color', 'assets', 'pages', 'form', 'services'] as $key) {
            if (! is_array($content) || ! array_key_exists($key, $content)) {
                throw new RuntimeException("Website content file is invalid: missing [{$key}].");
            }
        }
        if ($content['pages'] === [] || $content['assets'] === []) {
            throw new RuntimeException('Website content file has no pages or no assets.');
        }

        return $content;
    }

    /** The Business must exist, sit in the expected Workspace and carry the expected name; anything else aborts. */
    public function business(array $content): Business
    {
        $expected = $content['business'];
        $business = Business::query()->find($expected['id']);
        if ($business === null) {
            throw new RuntimeException("Business #{$expected['id']} does not exist.");
        }
        if ((int) $business->workspace_id !== (int) $expected['workspace_id']) {
            throw new RuntimeException("Business #{$business->id} is in Workspace #{$business->workspace_id}, expected #{$expected['workspace_id']}.");
        }
        if (mb_strtolower(trim((string) $business->name)) !== mb_strtolower(trim((string) $expected['name']))) {
            throw new RuntimeException("Business #{$business->id} is named [{$business->name}], expected [{$expected['name']}].");
        }

        return $business;
    }

    public function location(Business $business): BusinessLocation
    {
        return BusinessLocation::query()->where('business_id', $business->id)->orderByDesc('is_primary')->orderBy('id')->first()
            ?? throw new RuntimeException("Business #{$business->id} has no location to deploy the form to.");
    }

    /**
     * Counts of every CRM-side record a website build must never change.
     *
     * @return array<string, int>
     */
    public function crmFingerprint(Business $business): array
    {
        return [
            'contacts' => Contacts::query()->where('business_id', $business->id)->count(),
            'opportunities' => CrmOpportunity::query()->where('business_id', $business->id)->count(),
            'pipelines' => CrmPipeline::query()->where('business_id', $business->id)->count(),
            'locations' => BusinessLocation::query()->where('business_id', $business->id)->count(),
        ];
    }

    /**
     * Read-only: what a build would do.
     *
     * @return array<string, mixed>
     */
    public function plan(Business $business, array $content, ?string $assetsDir): array
    {
        $website = Website::query()->where('business_id', $business->id)->first();
        $found = $assetsDir !== null && is_dir($assetsDir) ? $this->index($assetsDir) : [];
        $missing = array_keys(array_filter($content['assets'], fn ($e) => ! isset($found[$e['sha256']])));

        return [
            'business' => "#{$business->id} {$business->name} (workspace #{$business->workspace_id})",
            'website' => $website === null ? 'would be CREATED' : "exists (#{$website->id}, status ".(string) ($website->status->value ?? $website->status).') and would be REUSED',
            'existing_pages' => $website?->pages()->count() ?? 0,
            'pages' => array_map(fn ($p) => $p['title'], $content['pages']),
            'assets_expected' => count($content['assets']),
            'assets_missing' => $missing,
            'blockers' => $content['blockers'] ?? [],
            'would_publish' => $missing === [] && ($content['blockers'] ?? []) === [],
            'crm' => $this->crmFingerprint($business),
        ];
    }

    /** @return array{website: Website, report: array<string, int|string>, missing: list<string>} */
    public function build(Business $business, BusinessLocation $location, int $actorUserId, string $assetsDir, array $content): array
    {
        $files = $this->index($assetsDir);
        $missing = array_keys(array_filter($content['assets'], fn ($e) => ! isset($files[$e['sha256']])));
        if ($missing !== []) {
            throw new RuntimeException('Assets missing from the assets directory (nothing written): '.implode(', ', $missing));
        }
        $blockers = $content['blockers'] ?? [];

        $this->catalogAndServices($business, $content, $actorUserId);

        $website = Website::query()->where('business_id', $business->id)->first();
        if ($website === null) {
            $template = WebsiteTemplate::query()->where('key', $content['template_key'])->first()
                ?? WebsiteTemplate::create(collect(WebsiteTemplateSeeder::templates())->firstWhere('key', $content['template_key']));
            $website = $this->starter->createShellFromTemplate($business, $template)->fresh();
        }
        $this->look->setBrandColor($website, $content['brand_color']);
        $this->look->setSecondaryColor($website, $content['secondary_color']);
        $website = $website->fresh();

        foreach ($content['assets'] as $role => $entry) {
            $this->assets[$role] = $this->storeAsset($website, $role, $files[$entry['sha256']], $entry);
        }

        $website->refresh();
        if (isset($content['assets']['logo']) && ! isset($website->theme['logo_asset_uid'])) {
            $this->look->setLogo($website, $this->upload($files[$content['assets']['logo']['sha256']], 'logo'), $content['assets']['logo']['alt']);
        }
        if (isset($content['assets']['hero']) && ! isset($website->fresh()->theme['hero_asset_uid'])) {
            $this->look->setHero($website, $this->upload($files[$content['assets']['hero']['sha256']], 'hero'), $content['assets']['hero']['alt']);
        }
        $website = $website->fresh();
        $legal = $content['legal_urls'] ?? [];
        if (($legal['privacy'] ?? null) !== null || ($legal['terms'] ?? null) !== null) {
            $this->look->setLegalLinks($website, $legal['privacy'] ?? null, $legal['terms'] ?? null);
            $website = $website->fresh();
        }

        $deployment = $this->quoteForm($business, $location, $actorUserId, $content['form']);

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

        // Never publish a site with a missing asset or a known content blocker: report instead.
        $publish = $blockers === [];
        if ($publish) {
            $this->publisher->publish($website, $actorUserId);
            $website = $website->fresh();
        }

        return [
            'website' => $website,
            'report' => [
                'pages' => $website->pages()->count(),
                'page_titles' => $website->pages()->orderBy('id')->pluck('title')->implode(' | '),
                'assets_stored' => $website->assets()->count(),
                'assets_expected' => count($content['assets']),
                'status' => (string) ($website->status->value ?? $website->status),
                'public_id' => (string) $website->public_id,
                'preview_url' => $publish ? rtrim((string) config('app.url'), '/').'/sites/'.$website->public_id : 'not published (draft only)',
                'published' => $publish ? 'yes (platform preview path only; no custom domain, no DNS)' : 'NO: '.implode(' ', $blockers),
                'form_deployment_uid' => (string) $deployment->uid,
            ],
            'missing' => $blockers,
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

    /** @param array<string, mixed> $content */
    private function catalogAndServices(Business $business, array $content, int $actorUserId): void
    {
        $this->services->syncForBusiness($business, array_map(
            fn (array $s, int $i) => $s + ['sort_order' => $i, 'is_primary' => $i === 0],
            $content['services'],
            array_keys($content['services']),
        ));

        foreach ($content['catalog'] ?? [] as $item) {
            $price = $item['price_minor'] ?? null;
            $attributes = ['type' => $item['type'], 'name' => $item['name'], 'description' => $item['description'], 'price_minor' => $price, 'currency_code' => $price === null ? null : 'USD', 'featured' => false, 'source_questionnaire_item_key' => $item['key']];
            $existing = $this->catalog->findBySourceKey($business, $item['key']);
            $existing === null ? $this->catalog->create($business, $attributes, $actorUserId) : $this->catalog->update($business, $existing, $attributes);
        }
    }

    /** @param array<string, mixed> $conf */
    private function quoteForm(Business $business, BusinessLocation $location, int $actorUserId, array $conf): \App\Models\FormDeployment
    {
        $form = Form::query()->where('business_id', $business->id)->where('name', self::FORM_NAME)->first();

        if ($form === null) {
            $defs = CustomFieldDefinition::query()->where('business_id', $business->id)->get()->keyBy(fn ($d) => mb_strtolower((string) $d->label));
            $pipeline = CrmPipeline::query()->where('business_id', $business->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->first();
            $label = (string) $conf['business_label'];

            $fields = [
                ['label' => 'Your name', 'type' => 'text', 'required' => true, 'contact_name' => true],
                ['label' => 'Phone', 'type' => 'phone', 'required' => true],
                ['label' => 'Email', 'type' => 'email', 'required' => true],
                ['label' => 'Event date', 'type' => 'date', 'required' => true] + (isset($defs['event date']) ? ['custom_field_uid' => $defs['event date']->uid] : []),
                ['label' => 'What type of event are you planning?', 'type' => 'multi_select', 'required' => true, 'options' => implode("\n", $conf['event_types'])] + (isset($defs['event type']) ? ['custom_field_uid' => $defs['event type']->uid] : []),
                ['label' => $conf['location_label'], 'type' => 'text', 'required' => false],
                ['label' => 'Anything else we should know?', 'type' => 'textarea', 'required' => false],
                // Both consents are optional and recorded separately; neither is required to inquire.
                ['label' => sprintf(self::CONSENT_TRANSACTIONAL, $label), 'type' => 'consent_transactional', 'required' => false],
                ['label' => sprintf(self::CONSENT_MARKETING, $label), 'type' => 'consent_marketing', 'required' => false],
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
