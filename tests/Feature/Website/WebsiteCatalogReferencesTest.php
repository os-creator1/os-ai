<?php

namespace Tests\Feature\Website;

use App\Library\Catalog\CatalogItemManager;
use App\Library\Website\WebsiteCatalogReferences;
use App\Library\Website\WebsiteStarterDraftService;
use App\Models\CatalogItem;
use App\Models\Website;
use App\Models\WebsiteRevision;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Packages & Products is the one pricing truth: Website package blocks hold
 * the canonical `catalog_item_uid`, resolve name + price live for the
 * editor's preview and for every publish, and an already-published site is
 * honestly flagged out of sync (with the existing republish path) when the
 * catalog moves on.
 */
class WebsiteCatalogReferencesTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    /** @return array{0: object, 1: object, 2: object, 3: CatalogItem, 4: Website} */
    private function generatedSite(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindDistinctAiClient();
        $this->completeV2Setup($workspace, $business);
        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))->assertRedirect();

        return [$customer, $business, $workspace, CatalogItem::where('business_id', $business->id)->sole(), Website::where('business_id', $business->id)->sole()];
    }

    private function packagesPage(Website $website)
    {
        return $website->pages()->where('slug', 'packages')->firstOrFail();
    }

    private function packageItems(array $sections): array
    {
        return collect($sections)->where('type', 'services')->flatMap(fn ($s) => $s['data']['items'])->values()->all();
    }

    private function editCatalog(object $business, CatalogItem $item, array $attributes): void
    {
        // The catalog change must land strictly AFTER the publish second.
        $this->travel(5)->seconds();
        app(CatalogItemManager::class)->update($business, $item, $attributes);
    }

    private function publish(object $workspace, object $business): void
    {
        $this->post($this->wizardUrl($workspace, $business, 'publish'))->assertRedirect();
    }

    public function test_generated_package_blocks_reference_their_canonical_catalog_row(): void
    {
        [, , , $item, $website] = $this->generatedSite();

        $items = $this->packageItems($this->packagesPage($website)->sections);

        $this->assertCount(1, $items);
        $this->assertSame($item->uid, $items[0]['catalog_item_uid']);
    }

    public function test_the_starter_draft_also_references_the_catalog_row(): void
    {
        [, $business] = $this->entitledTenant();
        $item = app(CatalogItemManager::class)->create($business, ['type' => 'package', 'name' => 'Starter Pack', 'price_minor' => 50000, 'currency_code' => 'USD']);

        $website = app(WebsiteStarterDraftService::class)->createFromTemplate($business, WebsiteTemplate::findActiveOrFail('photo_booth_modern'));

        $uids = collect($website->pages()->get())->flatMap(fn ($p) => $this->packageItems($p->sections))->pluck('catalog_item_uid')->filter()->unique()->all();
        $this->assertSame([$item->uid], $uids);
    }

    public function test_the_editor_preview_shows_todays_catalog_name_and_price_without_a_rebuild(): void
    {
        [, $business, $workspace, $item, $website] = $this->generatedSite();
        $page = $this->packagesPage($website);

        app(CatalogItemManager::class)->update($business, $item, ['name' => 'Essential Booth Deluxe', 'price_minor' => 89900, 'currency_code' => 'USD']);

        $this->get($this->wizardUrl($workspace, $business, 'preview', [$page->uid]))
            ->assertOk()
            ->assertSee('Essential Booth Deluxe')
            ->assertSee('USD 899.00');

        // The stored draft copy is untouched (manual page edits are never rewritten).
        $this->assertSame('Essential Booth', $this->packageItems($page->fresh()->sections)[0]['name']);
    }

    public function test_publishing_resolves_the_latest_catalog_data_into_the_revision(): void
    {
        [, $business, $workspace, $item] = $this->generatedSite();

        app(CatalogItemManager::class)->update($business, $item, ['price_minor' => 79900, 'currency_code' => 'USD']);
        $this->publish($workspace, $business);

        $revision = WebsiteRevision::orderByDesc('id')->firstOrFail();
        $packages = collect($revision->snapshot['pages'])->firstWhere('slug', 'packages');
        $row = $this->packageItems($packages['sections'])[0];

        $this->assertSame('USD 799.00', $row['price_label']);
        $this->assertSame($item->uid, $row['catalog_item_uid']);
    }

    public function test_an_archived_package_is_dropped_from_what_gets_published(): void
    {
        [, $business, $workspace, $item] = $this->generatedSite();
        app(CatalogItemManager::class)->archive($business, $item);

        $this->publish($workspace, $business);

        $revision = WebsiteRevision::orderByDesc('id')->firstOrFail();
        $packages = collect($revision->snapshot['pages'])->firstWhere('slug', 'packages');
        $this->assertNull($packages === null ? null : collect($packages['sections'])->firstWhere('type', 'services'), 'A packages section with no live package disappears.');
    }

    public function test_a_published_revision_stays_immutable_and_the_site_is_flagged_out_of_sync_until_republished(): void
    {
        [, $business, $workspace, $item, $website] = $this->generatedSite();
        $this->publish($workspace, $business);
        $first = WebsiteRevision::orderByDesc('id')->firstOrFail();

        $this->assertFalse(app(WebsiteCatalogReferences::class)->staleness($website->fresh())['out_of_sync'], 'Freshly published: in sync.');

        $this->editCatalog($business, $item, ['price_minor' => 99900, 'currency_code' => 'USD']);

        // The old revision is exactly as published...
        $stored = collect($first->fresh()->snapshot['pages'])->firstWhere('slug', 'packages');
        $this->assertSame('USD 699.00', $this->packageItems($stored['sections'])[0]['price_label']);

        // ...and the catalog change is reported, naming the package.
        $staleness = app(WebsiteCatalogReferences::class)->staleness($website->fresh());
        $this->assertTrue($staleness['out_of_sync']);
        $this->assertSame(['Essential Booth'], $staleness['changed']);

        // Studio says so plainly and offers the sync path.
        $this->get($this->wizardUrl($workspace, $business, 'studio.show'))
            ->assertOk()
            ->assertSee('Your packages changed after you last published')
            ->assertSee('Essential Booth')
            ->assertSee('Publish update');

        // Publishing again is the sync: new revision with today's price, banner gone.
        $this->travel(5)->seconds();
        $this->publish($workspace, $business);
        $second = WebsiteRevision::orderByDesc('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $latest = collect($second->snapshot['pages'])->firstWhere('slug', 'packages');
        $this->assertSame('USD 999.00', $this->packageItems($latest['sections'])[0]['price_label']);

        $this->assertFalse(app(WebsiteCatalogReferences::class)->staleness($website->fresh())['out_of_sync']);
        $this->get($this->wizardUrl($workspace, $business, 'studio.show'))->assertDontSee('Your packages changed after you last published');
    }

    public function test_an_archived_package_makes_the_published_site_stale_as_removed(): void
    {
        [, $business, $workspace, $item, $website] = $this->generatedSite();
        $this->publish($workspace, $business);

        $this->travel(5)->seconds();
        app(CatalogItemManager::class)->archive($business, $item);

        $staleness = app(WebsiteCatalogReferences::class)->staleness($website->fresh());
        $this->assertTrue($staleness['out_of_sync']);
        $this->assertSame(['Essential Booth'], $staleness['removed']);
    }

    public function test_a_site_that_never_published_or_has_no_references_is_never_flagged(): void
    {
        [, $business, , $item, $website] = $this->generatedSite();

        $this->editCatalog($business, $item, ['price_minor' => 1, 'currency_code' => 'USD']);

        // Unpublished: the preview is already live, there is nothing stale to warn about.
        $this->assertFalse(app(WebsiteCatalogReferences::class)->staleness($website->fresh())['out_of_sync']);
    }

    public function test_the_resolver_keeps_unreferenced_items_and_never_overwrites_an_existing_reference(): void
    {
        [, $business] = $this->entitledTenant();
        $item = app(CatalogItemManager::class)->create($business, ['type' => 'package', 'name' => 'Real Name', 'price_minor' => 10000, 'currency_code' => 'USD']);
        $references = app(WebsiteCatalogReferences::class);

        $sections = [['type' => 'services', 'data' => ['heading' => 'Packages', 'items' => [
            ['name' => 'Hand written', 'price_label' => 'Call us'],
            ['name' => 'Stale Name', 'price_label' => 'USD 1.00', 'catalog_item_uid' => $item->uid],
        ]]]];

        $resolved = $references->resolveSections($sections, (int) $business->id);
        $rows = $resolved[0]['data']['items'];

        $this->assertSame('Hand written', $rows[0]['name']);
        $this->assertSame('Call us', $rows[0]['price_label']);
        $this->assertSame('Real Name', $rows[1]['name']);
        $this->assertSame('USD 100.00', $rows[1]['price_label']);

        $stamped = $references->stampPackagePages([['page_type' => 'packages', 'sections' => [['type' => 'services', 'data' => ['items' => [
            ['name' => 'real name'],
            ['name' => 'Other', 'catalog_item_uid' => 'keep-me'],
        ]]]]]], (int) $business->id);
        $this->assertSame($item->uid, $stamped[0]['sections'][0]['data']['items'][0]['catalog_item_uid']);
        $this->assertSame('keep-me', $stamped[0]['sections'][0]['data']['items'][1]['catalog_item_uid']);
    }

    public function test_a_quote_only_package_resolves_with_no_invented_price(): void
    {
        [, $business] = $this->entitledTenant();
        $item = app(CatalogItemManager::class)->create($business, ['type' => 'package', 'name' => 'Custom Quote']);

        $resolved = app(WebsiteCatalogReferences::class)->resolveSections([['type' => 'services', 'data' => ['heading' => 'P', 'items' => [
            ['name' => 'Custom Quote', 'price_label' => 'USD 5.00', 'catalog_item_uid' => $item->uid],
        ]]]], (int) $business->id);

        $this->assertNull($resolved[0]['data']['items'][0]['price_label']);
    }

    public function test_another_businesss_package_uid_is_never_resolved(): void
    {
        [, $business] = $this->entitledTenant();
        [, $other] = $this->entitledTenant();
        $foreign = app(CatalogItemManager::class)->create($other, ['type' => 'package', 'name' => 'Not Yours', 'price_minor' => 100, 'currency_code' => 'USD']);

        $resolved = app(WebsiteCatalogReferences::class)->resolveSections([['type' => 'services', 'data' => ['heading' => 'P', 'items' => [
            ['name' => 'Mine', 'catalog_item_uid' => $foreign->uid],
        ]]]], (int) $business->id);

        $this->assertSame([], $resolved, 'A reference to a package this business does not own resolves to nothing.');
    }
}
