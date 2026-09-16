<?php

namespace Tests\Feature\Contacts;

use App\Enums\Automation\Workflow\ContactCreationSource;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Repositories\Eloquent\EloquentContactsRepository;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\TestCase;

/**
 * Implementation Contract 08B Part 2/§8 — every live Contacts creation site
 * resolves `location_id`, driven through its REAL production entry point
 * (never a test-side copy of the writer's own logic).
 *
 * MECHANICAL FINDING (implementation-time correction): the original recon
 * grep (`Contacts::create(`/`Contacts::insert(`/`new Contacts(`) missed a
 * real writer — EloquentContactsRepository::createContactFromRequest(),
 * which resolves via `$contactGroups->subscribers()->firstOrNew(...)` then
 * a plain `->save()`, never a literal `Contacts::create(`. This is the
 * ACTUAL writer behind ContactsController::storeContact() (the in-app "Add
 * contact" form), the public opt-in subscription form, and both API
 * Contacts controllers — four real callers the original inventory did not
 * separately account for. It has been fixed alongside the other four
 * writers found by the literal grep:
 *   1. DLRController.php (inbound opt-in auto-create)
 *   2. EloquentContactsRepository::storeContact() (a tested, allowlisted
 *      repository method with no current controller caller — real code,
 *      not dead code, per ContactCreatedTriggerTest's own direct coverage)
 *   3. EloquentContactsRepository::createContactFromRequest() (this file)
 *   4. ContactsController::storeImportContact() (paste-import raw insert)
 *   5. ContactGroups::import() (CSV raw SQL insert)
 *
 * Sites 3, 4 and 5 are exercised below through their real methods. Site 1
 * (DLRController's inbound-SMS keyword auto-reply path) is not driven
 * end-to-end here: reaching it requires the full inbound-SMS billing/
 * coverage/keyword fixture stack (SendingServer, PhoneNumbers, Keywords,
 * PlansCoverageCountries, subscription state) with no material Location
 * logic difference from the already-covered sites — it calls the exact
 * same `Contacts::singleActiveLocationIdFor()` helper, proven correct by
 * ContactsLocationBackfillV1Test's coverage of that helper's every branch.
 * Flagged here rather than silently skipped. Site 2 has no controller
 * caller to drive through, so its test coverage is
 * ContactCreatedTriggerTest's own existing direct-call coverage plus this
 * slice's code change.
 */
class ContactsLocationCreationSitesTest extends TestCase
{
    use CreatesAutomationFixtures;
    use UsesFreshSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();
    }

    private function location(Business $business, array $overrides = []): BusinessLocation
    {
        return BusinessLocation::create(array_merge([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'country_code' => 'US',
        ], $overrides));
    }

    /**
     * Site 3 — createContactFromRequest(), the real "Add contact" writer.
     */
    public function test_create_contact_from_request_resolves_a_single_active_location(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->location($business);
        $group = $this->contactGroup($business);

        [$validator, $subscriber] = app(EloquentContactsRepository::class)->createContactFromRequest($group, [
            'PHONE' => '12025559001',
        ], ContactCreationSource::Manual);

        $this->assertNotNull($subscriber, 'Real write must succeed: ' . ($validator?->errors()->first() ?? ''));
        $this->assertSame((int) $location->id, (int) $subscriber->fresh()->location_id);
    }

    public function test_create_contact_from_request_leaves_several_active_locations_null(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $this->location($business);
        $this->location($business);
        $group = $this->contactGroup($business);

        [, $subscriber] = app(EloquentContactsRepository::class)->createContactFromRequest($group, [
            'PHONE' => '12025559002',
        ], ContactCreationSource::Manual);

        $this->assertNull($subscriber->fresh()->location_id);
    }

    /**
     * Site 4 — the real paste-import raw array insert.
     */
    public function test_paste_import_resolves_a_single_active_location(): void
    {
        [$customer, $business] = $this->entitledTenant();
        $location = $this->location($business);
        $group = $this->contactGroup($business);
        $this->authenticateAsCustomerForContacts($customer);

        $this->post('/contacts/' . $group->uid . '/import', [
            'recipients' => "12025559101\n12025559102",
            'delimiter' => 'new_line',
        ]);

        $imported = Contacts::query()->where('group_id', $group->id)->get();

        $this->assertCount(2, $imported);
        $this->assertTrue($imported->every(fn (Contacts $c) => (int) $c->location_id === (int) $location->id));
    }

    /**
     * Site 5 — the real CSV raw SQL insert, driven through
     * ContactGroups::import() directly (the same real method
     * ImportContacts::handle() calls), mirroring
     * AutomationsImportPolicyTest's own precedent for this writer.
     */
    public function test_csv_import_resolves_a_single_active_location(): void
    {
        [, $business] = $this->entitledTenant();
        $location = $this->location($business);
        $group = $this->contactGroup($business, 'Imported');

        $csv = tempnam(sys_get_temp_dir(), 'contract08b-import-') . '.csv';
        file_put_contents($csv, implode("\n", [
            'PHONE,FIRST_NAME,LAST_NAME',
            '12025558101,Ada,Lovelace',
            '12025558102,Grace,Hopper',
        ]) . "\n");

        $map = $group->contactGroupFields()->get()
            ->whereIn('tag', ['PHONE', 'FIRST_NAME', 'LAST_NAME'])
            ->mapWithKeys(fn ($field) => [$field->tag => $field->id])
            ->all();

        try {
            $group->fresh()->import($csv, $map);
        } finally {
            @unlink($csv);
        }

        $imported = Contacts::query()->where('group_id', $group->id)->get();

        $this->assertCount(2, $imported, 'The real import path must have created the Business-scoped contacts.');
        $this->assertTrue($imported->every(fn (Contacts $c) => (int) $c->location_id === (int) $location->id));
    }

    public function test_csv_import_leaves_several_active_locations_null(): void
    {
        [, $business] = $this->entitledTenant();
        $this->location($business);
        $this->location($business);
        $group = $this->contactGroup($business, 'Imported');

        $csv = tempnam(sys_get_temp_dir(), 'contract08b-import-') . '.csv';
        file_put_contents($csv, implode("\n", [
            'PHONE,FIRST_NAME,LAST_NAME',
            '12025558201,Edsger,Dijkstra',
        ]) . "\n");

        $map = $group->contactGroupFields()->get()
            ->whereIn('tag', ['PHONE', 'FIRST_NAME', 'LAST_NAME'])
            ->mapWithKeys(fn ($field) => [$field->tag => $field->id])
            ->all();

        try {
            $group->fresh()->import($csv, $map);
        } finally {
            @unlink($csv);
        }

        $imported = Contacts::query()->where('group_id', $group->id)->sole();

        $this->assertNull($imported->location_id);
    }

    private function authenticateAsCustomerForContacts($customer): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend', 'create_contact'])]);
        $this->actingAs($customer->user);
    }
}
