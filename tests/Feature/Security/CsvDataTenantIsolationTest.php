<?php

namespace Tests\Feature\Security;

use App\Models\CsvData;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Security fix F2: csv_data_file_id was resolved with CsvData::find() and no owner check.
 */
class CsvDataTenantIsolationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function csv(int $userId, string $type = CsvData::TYPE_CAMPAIGN, string $data = 'app/bulk_sms/x.csv'): CsvData
    {
        return CsvData::create([
            'user_id' => $userId, 'ref_id' => 'ref-' . uniqid(), 'ref_type' => $type,
            'csv_filename' => 'x.csv', 'csv_header' => false, 'csv_data' => $data,
        ]);
    }

    /** @return array{0: Customer, 1: Customer} */
    private function tenants(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        return [$this->createCustomer(), $this->createCustomer()];
    }

    public function test_find_owned_by_returns_only_the_uploaders_own_file(): void
    {
        [$a, $b] = $this->tenants();
        $fileA = $this->csv($a->user_id);

        $this->assertSame($fileA->id, CsvData::findOwnedBy($fileA->id, $a->user_id)?->id);
        $this->assertNull(CsvData::findOwnedBy($fileA->id, $b->user_id));
        $this->assertNull(CsvData::findOwnedBy($fileA->id, null));
        $this->assertNull(CsvData::findOwnedBy(null, $a->user_id));
        $this->assertNull(CsvData::findOwnedBy($fileA->id, $a->user_id, CsvData::TYPE_CONTACT));
        $this->assertNotNull(CsvData::findOwnedBy($fileA->id, $a->user_id, CsvData::TYPE_CAMPAIGN));
    }

    public function test_contact_import_with_foreign_csv_id_is_refused_and_foreign_row_survives(): void
    {
        [$a, $b] = $this->tenants();
        $groupB = \App\Models\ContactGroups::create(['customer_id' => $b->user_id, 'name' => 'B', 'status' => true]);
        $fileA = $this->csv($a->user_id, CsvData::TYPE_CONTACT, json_encode([['15550001111', 'Victim']]));

        $b->user->email_verified_at = now();
        $b->user->save();
        $this->withSession(['permissions' => collect(['access_backend', 'create_contact', 'view_contact'])]);
        $this->actingAs($b->user);

        $this->post(route('customer.contact.import_process', $groupB->uid), [
            'csv_data_file_id' => $fileA->id,
            'fields' => ['phone', 'first_name'],
        ]);

        $this->assertDatabaseHas('csv_data', ['id' => $fileA->id]);
        $this->assertSame(0, \App\Models\Contacts::where('group_id', $groupB->id)->count());
    }

    public function test_own_contact_import_still_works(): void
    {
        [, $b] = $this->tenants();
        $groupB = \App\Models\ContactGroups::create(['customer_id' => $b->user_id, 'name' => 'B', 'status' => true]);
        $fileB = $this->csv($b->user_id, CsvData::TYPE_CONTACT, json_encode([['15550002222', 'Own']]));

        $b->user->email_verified_at = now();
        $b->user->save();
        $this->withSession(['permissions' => collect(['access_backend', 'create_contact', 'view_contact'])]);
        $this->actingAs($b->user);

        $this->post(route('customer.contact.import_process', $groupB->uid), [
            'csv_data_file_id' => $fileB->id,
            'fields' => ['phone', 'first_name'],
        ]);

        // The record was found and consumed (the legacy handler deletes the staged row once imported).
        $this->assertDatabaseMissing('csv_data', ['id' => $fileB->id]);
    }

    public function test_campaign_send_using_file_refuses_a_foreign_csv_and_leaves_it_untouched(): void
    {
        [$a, $b] = $this->tenants();
        $fileA = $this->csv($a->user_id);

        $b->user->email_verified_at = now();
        $b->user->save();
        $this->actingAs($b->user);

        $response = app(\App\Repositories\Eloquent\EloquentCampaignRepository::class)
            ->sendUsingFile(new \App\Models\Campaigns(), ['csv_data_file_id' => $fileA->id, 'form_data' => '{}']);

        $this->assertSame('error', $response->getData()->status);
        $this->assertSame(__('locale.filezone.insert_valid_csv_file'), $response->getData()->message);
        $this->assertDatabaseHas('csv_data', ['id' => $fileA->id]);
    }
}
