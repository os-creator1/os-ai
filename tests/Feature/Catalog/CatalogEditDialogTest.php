<?php

namespace Tests\Feature\Catalog;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\TestCase;

/** The list's "Edit item" dialog: real data in, JSON out, same manager and gates. */
class CatalogEditDialogTest extends TestCase
{
    use CreatesCatalogHttpFixtures;
    use RefreshDatabase;

    private function ajax(string $url, array $data)
    {
        return $this->withHeaders(['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])->postJson($url, $data);
    }

    private function dialogData(string $html): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="catalog-edit-data">(.*?)</script>#s', $html, $m));

        return json_decode($m[1], true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_the_list_renders_the_dialog_with_each_items_real_values_and_fallback_links(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '${PRICE}', 'status' => true]);
        $location = $this->catalogLocation($business, 'Primary Location');
        $package = $this->catalogItem($business, 'Wedding Classic', ['description' => 'All day', 'price_minor' => 120000]);
        $product = $this->catalogItem($business, 'Backdrop', ['type' => 'product', 'price_minor' => null, 'currency_code' => null]);
        $this->authenticateAs($owner);

        $html = $this->get($this->catalogRoute('index', $workspace, $business))->assertOk()->getContent();
        $data = $this->dialogData($html);

        $this->assertStringContainsString('id="catalog-edit-dialog"', $html);
        $this->assertStringContainsString('data-catalog-edit="' . $package->uid . '"', $html);
        $this->assertStringContainsString('href="' . $this->catalogRoute('edit', $workspace, $business, [$package->uid]) . '"', $html);
        $this->assertStringContainsString('data-role="catalog-edit-manage-locations"', $html);
        $this->assertStringContainsString($this->catalogRoute('locations.index', $workspace, $business), $html);

        $this->assertSame(['package', 'Wedding Classic', 'All day', 'fixed', '1200.00', 'USD', 1, 1], [
            $data[$package->uid]['type'], $data[$package->uid]['name'], $data[$package->uid]['description'], $data[$package->uid]['pricing'],
            $data[$package->uid]['price'], $data[$package->uid]['currency'], $data[$package->uid]['offered'], $data[$package->uid]['locations'],
        ]);
        $this->assertSame(['product', 'quote', '', ''], [$data[$product->uid]['type'], $data[$product->uid]['pricing'], $data[$product->uid]['price'], $data[$product->uid]['currency']]);
        $this->assertSame($this->catalogRoute('update', $workspace, $business, [$package->uid]), $data[$package->uid]['updateUrl']);
        $this->assertSame($this->catalogRoute('archive', $workspace, $business, [$package->uid]), $data[$package->uid]['archiveUrl']);

        // The full-page edit URL still works.
        $this->get($this->catalogRoute('edit', $workspace, $business, [$package->uid]))->assertOk()->assertSee('data-role="catalog-edit-form"', false);
    }

    public function test_offered_counts_follow_location_availability_and_reach(): void
    {
        [, $business, $workspace] = $this->catalogTenant();
        $downtown = $this->catalogLocation($business, 'Downtown');
        $airport = $this->catalogLocation($business, 'Airport');
        $item = $this->catalogItem($business);
        $owner = $this->staffWithFullReach($workspace);
        $this->authenticateAs($owner);
        $this->post($this->catalogRoute('locations.enabled', $workspace, $business, [$airport->uid, $item->uid]), ['is_enabled' => 0])->assertRedirect();

        $data = $this->dialogData($this->get($this->catalogRoute('index', $workspace, $business))->getContent());
        $this->assertSame([1, 2], [$data[$item->uid]['offered'], $data[$item->uid]['locations']]);

        // A staff member who can reach only Downtown never sees Airport counted.
        $this->authenticateAs($this->staffGrantedOnly($workspace, $downtown));
        $data = $this->dialogData($this->get($this->catalogRoute('index', $workspace, $business))->getContent());
        $this->assertSame([1, 1], [$data[$item->uid]['offered'], $data[$item->uid]['locations']]);
    }

    public function test_saving_through_the_dialog_updates_the_item_and_flashes(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $item = $this->catalogItem($business, 'Old name', ['price_minor' => 10000]);
        $this->authenticateAs($owner);
        $url = $this->catalogRoute('update', $workspace, $business, [$item->uid]);

        $this->ajax($url, ['type' => 'product', 'name' => 'New name', 'description' => 'Nice', 'pricing' => 'fixed', 'price' => '75.50', 'currency_code' => 'USD'])
            ->assertOk()->assertJsonPath('message', 'Saved.');

        $fresh = $item->fresh();
        $this->assertSame(['product', 'New name', 'Nice', 7550, 'USD'], [$fresh->type->value, $fresh->name, $fresh->description, $fresh->price_minor, $fresh->currency_code]);
        $this->get($this->catalogRoute('index', $workspace, $business))->assertSee('Saved.')->assertSee('USD 75.50');
    }

    public function test_switching_to_quote_only_removes_the_price_and_currency(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $item = $this->catalogItem($business);
        $this->authenticateAs($owner);

        $this->ajax($this->catalogRoute('update', $workspace, $business, [$item->uid]), ['type' => 'package', 'name' => 'Wedding Package', 'pricing' => 'quote'])->assertOk();

        $this->assertNull($item->fresh()->price_minor);
        $this->assertNull($item->fresh()->currency_code);
    }

    public function test_validation_and_manager_refusals_come_back_as_json_and_change_nothing(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $item = $this->catalogItem($business, 'Keep me', ['price_minor' => 10000]);
        $this->authenticateAs($owner);
        $url = $this->catalogRoute('update', $workspace, $business, [$item->uid]);

        $this->ajax($url, ['type' => 'package', 'name' => '', 'pricing' => 'quote'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->ajax($url, ['type' => 'package', 'name' => 'A', 'pricing' => 'fixed', 'price' => '', 'currency_code' => 'USD'])->assertStatus(422)->assertJsonValidationErrors('price');
        $this->ajax($url, ['type' => 'package', 'name' => 'A', 'pricing' => 'fixed', 'price' => '10.999', 'currency_code' => 'USD'])->assertStatus(422)->assertJsonValidationErrors('catalog');
        $this->ajax($url, ['type' => 'package', 'name' => str_repeat('n', 161), 'pricing' => 'quote'])
            ->assertStatus(422)->assertJsonPath('errors.catalog.0', 'Use a name of 1 to 160 characters.');

        $fresh = $item->fresh();
        $this->assertSame(['Keep me', 10000], [$fresh->name, $fresh->price_minor]);
    }

    public function test_delete_in_the_dialog_is_the_existing_archive_action(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        $item = $this->catalogItem($business, 'Retire me');
        $this->authenticateAs($owner);

        $this->post($this->catalogRoute('archive', $workspace, $business, [$item->uid]))->assertRedirect($this->catalogRoute('index', $workspace, $business));

        $this->assertTrue($item->fresh()->isArchived());
        $this->get($this->catalogRoute('index', $workspace, $business))->assertSee('data-role="catalog-archived"', false);
    }

    public function test_another_business_item_cannot_be_updated_through_this_one(): void
    {
        [$owner, $business, $workspace] = $this->catalogTenant();
        [, $foreign] = $this->catalogTenant(WorkspacePlanTier::Core, 'Foreign Studio');
        $foreignItem = $this->catalogItem($foreign, 'Foreign');
        $this->authenticateAs($owner);

        $this->ajax($this->catalogRoute('update', $workspace, $business, [$foreignItem->uid]), ['type' => 'package', 'name' => 'Hacked', 'pricing' => 'quote'])->assertNotFound();
        $this->assertSame('Foreign', $foreignItem->fresh()->name);
    }
}
