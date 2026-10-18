<?php

namespace Tests\Feature\Seo\Content\Concerns;

use App\Enums\Catalog\CatalogItemType;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Seo\Content\ArticleManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Customer;
use App\Models\Website;
use App\Models\WebsiteArticle;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Seo\Concerns\CreatesSeoFixtures;

/**
 * SEO Content Engine V1 — the shared Photo Booth acceptance fixture: a Chicago photo booth company
 * with services (Digital, Glam, 360, Roaming, Wedding, Corporate), real catalog packages, Chicago plus
 * nearby service areas, and a PUBLISHED Website on an Active custom domain.
 *
 * Fast on purpose: the published snapshot is written directly (CreatesSeoFixtures::publishWebsite), not
 * driven through the whole wizard. Every page uid is a fixed, readable UUID so tests can name pages.
 *
 * The Business values are FIXTURES (reserved 555 phone, example email). Packages are priced 699 / 949 /
 * 1299 — the only dollar amounts an article about this fixture may legitimately mention.
 */
trait CreatesContentFixtures
{
    use CreatesSeoFixtures;

    public const PAGE_HOME = 'aaaaaaaa-0000-4000-8000-000000000001';
    public const PAGE_SERVICES = 'aaaaaaaa-0000-4000-8000-000000000002';
    public const PAGE_WEDDING = 'aaaaaaaa-0000-4000-8000-000000000003';
    public const PAGE_CORPORATE = 'aaaaaaaa-0000-4000-8000-000000000004';
    public const PAGE_360 = 'aaaaaaaa-0000-4000-8000-000000000005';
    public const PAGE_GLAM = 'aaaaaaaa-0000-4000-8000-000000000006';
    public const PAGE_CHICAGO = 'aaaaaaaa-0000-4000-8000-000000000007';
    public const PAGE_NAPERVILLE = 'aaaaaaaa-0000-4000-8000-000000000008';
    public const PAGE_PACKAGES = 'aaaaaaaa-0000-4000-8000-000000000009';
    public const PAGE_CONTACT = 'aaaaaaaa-0000-4000-8000-000000000010';
    public const PAGE_ABOUT = 'aaaaaaaa-0000-4000-8000-000000000011';
    public const PAGE_NOINDEX = 'aaaaaaaa-0000-4000-8000-000000000012';

    public const DOMAIN = 'jazminphotobooth.test';

    protected int $contentTenantCount = 0;

    protected function manager(): ArticleManager
    {
        return app(ArticleManager::class);
    }

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace, 3: Website}
     */
    protected function seedPhotoBoothBlueprintForContent(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $adminId = $this->platformAdminId();
        \App\Models\User::query()->whereKey($adminId)->update(['is_admin' => true]);

        (new \Database\Seeders\WebsiteTemplateSeeder())->run();

        $this->artisan('blueprint:seed-photo-booth', ['--actor' => $adminId])->assertExitCode(0);
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('documents:seed-photo-booth-templates', ['--actor' => $adminId]));
        $this->artisan('blueprint:seed-photo-booth-v2', ['--actor' => $adminId])->assertExitCode(0);
    }

    protected function photoBoothContentTenant(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, string $templateKey = 'photo_booth_modern', bool $withDomain = true, bool $withBlueprint = false): array
    {
        if ($withBlueprint) {
            // Seeded BEFORE the Business exists: the Blueprint installs when the Business gets its first plan.
            $this->seedPhotoBoothBlueprintForContent();
        }

        [$customer, $business, $workspace] = $this->entitledTenant($tier);

        DB::table('businesses')->where('id', $business->id)->update([
            'name' => 'Jazmin Photo Booth Co.',
            'phone' => '3125550147',
            'email' => 'hello@jazminphotobooth.example',
        ]);
        $business = $business->fresh();

        $this->createLocation($business, true, \App\Enums\Business\BusinessServiceMode::Storefront, [
            'name' => 'Chicago Studio', 'address_line_1' => '1200 W Fulton Market', 'city' => 'Chicago', 'region' => 'IL', 'postal_code' => '60607',
        ]);

        foreach ([['Essential', 69900, false], ['Signature', 94900, true], ['Luxe', 129900, false]] as $i => [$name, $price, $featured]) {
            CatalogItem::create([
                'business_id' => $business->id, 'type' => CatalogItemType::Package, 'name' => $name,
                'description' => $name . ' photo booth package.', 'price_minor' => $price, 'currency_code' => 'USD',
                'position' => $i, 'featured' => $featured,
            ]);
        }

        $website = $this->publishWebsite($business, $this->photoBoothPages());
        $website->update(['template_key' => $templateKey, 'name' => 'Jazmin Photo Booth Co.']);
        // The owner has released this website for search (the Website's own canonical state).
        $website->forceFill(['indexing_released_at' => now()])->save();

        if ($withDomain) {
            // A domain is globally unique: the first tenant of a test gets the named one, any further tenant a prefixed one.
            $domain = $this->contentTenantCount++ === 0 ? self::DOMAIN : 'tenant' . $this->contentTenantCount . '.' . self::DOMAIN;
            $website->domains()->create([
                'domain' => $domain, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active,
                'verification_token' => 'content-fixture', 'verified_at' => now(), 'activated_at' => now(),
            ]);
        }

        return [$customer, $business, $workspace, $website->fresh()];
    }

    /** @return array<int, array<string, mixed>> */
    protected function photoBoothPages(): array
    {
        $text = fn (string $heading, string $body) => [['type' => 'text', 'data' => ['heading' => $heading, 'body' => $body]]];

        return [
            $this->snapshotPage(self::PAGE_HOME, 'Chicago Photo Booth Rental', ['seo_title' => 'Chicago Photo Booth Rental | Jazmin Photo Booth Co.'], $text('Photo booths for every Chicago event', 'Digital, Glam, 360 and roaming photo booths for weddings and corporate events across Chicago.'), true),
            $this->snapshotPage(self::PAGE_SERVICES, 'Photo Booth Services', [], $text('Our photo booth services', 'Digital, Glam, 360 and Roaming booths.'), false, 'services'),
            $this->snapshotPage(self::PAGE_WEDDING, 'Wedding Photo Booth Rental', ['seo_title' => 'Wedding Photo Booth Rental in Chicago'], $text('Wedding photo booths', 'A booth for your reception.'), false, 'service-wedding-photo-booth-rental'),
            $this->snapshotPage(self::PAGE_CORPORATE, 'Corporate Event Photo Booth', [], $text('Corporate photo booths', 'Branded booths for company events.'), false, 'service-corporate-event-photo-booth'),
            $this->snapshotPage(self::PAGE_360, '360 Photo Booth Rental', [], $text('360 photo booth', 'A slow-motion video platform.'), false, 'service-360-photo-booth-rental'),
            $this->snapshotPage(self::PAGE_GLAM, 'Glam Photo Booth', [], $text('Glam booth', 'A beauty-lit booth.'), false, 'service-glam-photo-booth'),
            $this->snapshotPage(self::PAGE_CHICAGO, 'Photo Booth Rental in Chicago', [], $text('Serving Chicago', 'Chicago photo booth rental.'), false, 'serving-chicago'),
            $this->snapshotPage(self::PAGE_NAPERVILLE, 'Photo Booth Rental in Naperville', [], $text('Serving Naperville', 'Naperville photo booth rental.'), false, 'serving-naperville'),
            $this->snapshotPage(self::PAGE_PACKAGES, 'Photo Booth Packages and Pricing', [], $text('Packages', 'Essential, Signature and Luxe.'), false, 'packages'),
            $this->snapshotPage(self::PAGE_CONTACT, 'Contact', [], $text('Contact us', 'Request a quote.'), false, 'photo-booth-contact'),
            $this->snapshotPage(self::PAGE_ABOUT, 'About', [], $text('About us', 'A Chicago team.'), false, 'photo-booth-about'),
            $this->snapshotPage(self::PAGE_NOINDEX, 'Hidden Page', ['noindex' => true], $text('Hidden', 'Not for search.'), false, 'hidden-page'),
        ];
    }

    /** A body long enough to publish (>= 150 words) and free of unsupported claims. */
    protected function goodBody(string $link = ''): string
    {
        $paragraph = 'Planning a photo booth for your event starts with the guest list, the venue layout and the time you want the booth open. A booth works best when guests can walk up easily, so choose a spot near the action but away from the busiest doorway. Think about how long the line may get during peak moments, and leave enough room for a small group to pose together. ';

        return "## What to plan first\n\n" . str_repeat($paragraph, 2) . "\n\n## Choosing the right setup\n\n" . str_repeat($paragraph, 2) . ($link !== '' ? "\n\nSee our [wedding photo booth rental](page:{$link}) for options." : '') . "\n";
    }

    /**
     * A ready-to-publish draft article for the Business.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function draftArticle(Business $business, array $overrides = []): WebsiteArticle
    {
        return $this->manager()->create((int) $business->customer_id, $business, array_merge([
            'title' => 'How Much Space Does a Photo Booth Need?',
            'excerpt' => 'A practical look at the floor space, power and layout a photo booth needs at your venue.',
            'body' => $this->goodBody(self::PAGE_WEDDING),
            'meta_description' => 'A practical look at the floor space, power and layout a photo booth needs at your venue, with tips for planning.',
            'primary_topic' => 'how much space does a photo booth need',
            'search_intent' => 'planning',
            'supports_page_uid' => self::PAGE_WEDDING,
        ], $overrides));
    }

    protected function publishedArticle(Business $business, array $overrides = []): WebsiteArticle
    {
        $article = $this->draftArticle($business, $overrides);

        return $this->manager()->publish((int) $business->customer_id, $business, $article);
    }

    protected function locationFor(Business $business): BusinessLocation
    {
        return BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
    }
}
