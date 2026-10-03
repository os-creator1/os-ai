<?php

namespace Tests\Feature\Website;

use App\Library\Catalog\CatalogItemManager;
use App\Library\Website\WebsiteCatalogReferences;
use App\Models\CatalogItem;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * The package-sync contract end to end: ONE pricing truth (Packages &
 * Products). The Website stores a catalog uid, never an independent price;
 * every later catalog change reaches preview immediately and a published
 * site is flagged until republished; setup edits never push an old copy back;
 * and a package that disappears from the catalog fails SAFELY with a clear
 * owner-facing state.
 */
class WebsitePackageSyncContractTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    private function package(object $business, string $name, int $minor): CatalogItem
    {
        return app(CatalogItemManager::class)->create($business, ['type' => 'package', 'name' => $name, 'price_minor' => $minor, 'currency_code' => 'USD']);
    }

    private function selection(CatalogItem ...$items): array
    {
        return ['items' => array_map(fn (CatalogItem $i) => ['uid' => $i->uid, 'include' => '1', 'name' => $i->name, 'price' => number_format($i->price_minor / 100, 2, '.', ''), 'currency_code' => 'USD'], $items)];
    }

    /** @return array{0: object, 1: object, 2: object} */
    private function tenant(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->bindDistinctAiClient();

        return [$customer, $business, $workspace];
    }

    private function packagesSection(Website $website): array
    {
        $page = $website->pages()->where('slug', 'packages')->first();

        return $page === null ? [] : collect($page->sections)->where('type', 'services')->flatMap(fn ($s) => $s['data']['items'])->values()->all();
    }

    public function test_the_whole_contract_in_one_story(): void
    {
        [, $business, $workspace] = $this->tenant();
        $item = $this->package($business, 'Essential Booth', 69900);
        $this->completeV2Setup($workspace, $business, ['packages' => $this->selection($item)]);

        // 1. The setup answer holds the catalog uid ONLY.
        $answers = QuestionnaireResponse::where('business_id', $business->id)->sole()->answers;
        $this->assertSame([['uid' => $item->uid]], $answers['packages']);
        $this->assertStringNotContainsString('Essential Booth', json_encode($answers['packages']));
        $this->assertStringNotContainsString('699', json_encode($answers['packages']));

        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))->assertRedirect();
        $website = Website::where('business_id', $business->id)->sole();
        $page = $website->pages()->where('slug', 'packages')->firstOrFail();
        $this->assertSame($item->uid, $this->packagesSection($website)[0]['catalog_item_uid']);

        // 2. Editing the catalog price updates Preview at once; the stored draft copy is not an authority.
        app(CatalogItemManager::class)->update($business, $item, ['price_minor' => 74900, 'currency_code' => 'USD']);
        $this->get($this->wizardUrl($workspace, $business, 'preview', [$page->uid]))->assertOk()->assertSee('USD 749.00')->assertDontSee('USD 699.00');
        $this->assertSame('USD 699.00', $this->packagesSection($website->fresh())[0]['price_label'], 'The draft copy is untouched; preview resolves live.');

        // 3. Publish freezes the CURRENT canonical price.
        $this->post($this->wizardUrl($workspace, $business, 'publish'))->assertRedirect();
        $first = WebsiteRevision::orderByDesc('id')->firstOrFail();
        $this->assertSame('USD 749.00', collect(collect($first->snapshot['pages'])->firstWhere('slug', 'packages')['sections'])->firstWhere('type', 'services')['data']['items'][0]['price_label']);
        $this->assertFalse(app(WebsiteCatalogReferences::class)->staleness($website->fresh())['out_of_sync']);

        // 4. A later catalog change makes the published site out of sync...
        $this->travel(5)->seconds();
        app(CatalogItemManager::class)->update($business, $item, ['price_minor' => 84900, 'currency_code' => 'USD']);
        $this->assertTrue(app(WebsiteCatalogReferences::class)->staleness($website->fresh())['out_of_sync']);
        $this->get($this->wizardUrl($workspace, $business, 'studio.show'))->assertSee('Your packages changed after you last published')->assertSee('Publish update');

        // 5. ...and "Edit setup answers" does NOT overwrite it.
        $this->get($this->wizardUrl($workspace, $business, 'edit-setup'))->assertRedirect();
        $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['packages']))->assertOk()->assertSee('USD 849.00');
        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))->assertRedirect();
        $this->assertSame(84900, $item->fresh()->price_minor);

        // 6. Publish update uses the current canonical package and clears the flag; the old revision is unchanged.
        $this->travel(5)->seconds();
        $this->post($this->wizardUrl($workspace, $business, 'publish'))->assertRedirect();
        $second = WebsiteRevision::orderByDesc('id')->firstOrFail();
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('USD 849.00', collect(collect($second->snapshot['pages'])->firstWhere('slug', 'packages')['sections'])->firstWhere('type', 'services')['data']['items'][0]['price_label']);
        $this->assertSame('USD 749.00', collect(collect($first->fresh()->snapshot['pages'])->firstWhere('slug', 'packages')['sections'])->firstWhere('type', 'services')['data']['items'][0]['price_label']);
        $this->assertFalse(app(WebsiteCatalogReferences::class)->staleness($website->fresh())['out_of_sync']);
    }

    // ------------------------------------------- a package that disappears

    public function test_a_selected_package_archived_before_generation_is_left_out_safely_and_the_owner_is_told(): void
    {
        [, $business, $workspace] = $this->tenant();
        $keep = $this->package($business, 'Essential Booth', 69900);
        $gone = $this->package($business, 'Premium Booth', 99900);
        $this->completeV2Setup($workspace, $business, ['packages' => $this->selection($keep, $gone)]);

        app(CatalogItemManager::class)->archive($business, $gone);

        // The review screen lists only what is live and says what was left out.
        $html = $this->get($this->wizardUrl($workspace, $business, 'setup.review'))->assertOk()->getContent();
        $this->assertStringContainsString('Essential Booth', $html);
        $this->assertStringNotContainsString('Premium Booth', $html);
        $this->assertStringContainsString('no longer in Packages &amp; Products', $html);

        // Generation still succeeds, with only the live package.
        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))->assertRedirect($this->wizardUrl($workspace, $business, 'preview'));
        $items = $this->packagesSection(Website::where('business_id', $business->id)->sole());
        $this->assertSame(['Essential Booth'], array_column($items, 'name'));
    }

    public function test_when_every_selected_package_is_gone_generation_still_works_without_a_packages_page_and_says_so(): void
    {
        [, $business, $workspace] = $this->tenant();
        $only = $this->package($business, 'Essential Booth', 69900);
        $this->completeV2Setup($workspace, $business, ['packages' => $this->selection($only)]);
        app(CatalogItemManager::class)->archive($business, $only);

        $this->get($this->wizardUrl($workspace, $business, 'setup.review'))->assertOk()->assertSee('no longer in Packages &amp; Products', false)->assertDontSee('Essential Booth');

        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))->assertRedirect($this->wizardUrl($workspace, $business, 'preview'));
        $website = Website::where('business_id', $business->id)->sole();
        $this->assertNull($website->pages()->where('slug', 'packages')->first(), 'No packages, no packages page — and nothing invented.');
        $this->assertGreaterThan(0, $website->pages()->count());
    }

    public function test_the_packages_screen_never_offers_an_archived_package(): void
    {
        [, $business, $workspace] = $this->tenant();
        $live = $this->package($business, 'Essential Booth', 69900);
        $gone = $this->package($business, 'Premium Booth', 99900);
        $this->startV2Setup($workspace, $business);
        $this->postScreen($workspace, $business, 'packages', $this->selection($live, $gone));
        app(CatalogItemManager::class)->archive($business, $gone);

        $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['packages']))
            ->assertOk()
            ->assertSee('Essential Booth')
            ->assertDontSee('Premium Booth');
    }

    public function test_a_stale_form_that_still_includes_an_archived_package_is_refused_with_a_clear_message_and_saves_nothing(): void
    {
        [, $business, $workspace] = $this->tenant();
        $live = $this->package($business, 'Essential Booth', 69900);
        $gone = $this->package($business, 'Premium Booth', 99900);
        $this->startV2Setup($workspace, $business);
        app(CatalogItemManager::class)->archive($business, $gone);

        $this->postScreen($workspace, $business, 'packages', $this->selection($live, $gone))
            ->assertRedirect($this->wizardUrl($workspace, $business, 'setup.step', ['packages']))
            ->assertSessionHas('status', 'error')
            ->assertSessionHas('message', 'One of the selected packages is no longer available.');

        $this->assertNull(QuestionnaireResponse::where('business_id', $business->id)->sole()->answer('packages'), 'Nothing was saved.');
    }

    public function test_an_archived_package_on_a_published_site_is_reported_as_removed_not_silently_kept(): void
    {
        [, $business, $workspace] = $this->tenant();
        $item = $this->package($business, 'Essential Booth', 69900);
        $this->completeV2Setup($workspace, $business, ['packages' => $this->selection($item)]);
        $this->post($this->wizardUrl($workspace, $business, 'setup.generate'))->assertRedirect();
        $this->post($this->wizardUrl($workspace, $business, 'publish'))->assertRedirect();

        $this->travel(5)->seconds();
        app(CatalogItemManager::class)->archive($business, $item);

        $this->get($this->wizardUrl($workspace, $business, 'studio.show'))
            ->assertOk()
            ->assertSee('Your packages changed after you last published')
            ->assertSee('removed: Essential Booth');

        // Republishing drops it from the live site instead of showing a stale price.
        $this->travel(5)->seconds();
        $this->post($this->wizardUrl($workspace, $business, 'publish'))->assertRedirect();
        $latest = WebsiteRevision::orderByDesc('id')->firstOrFail();
        $packagesPage = collect($latest->snapshot['pages'])->firstWhere('slug', 'packages');
        $this->assertNull(collect($packagesPage['sections'] ?? [])->firstWhere('type', 'services'));
    }
}
