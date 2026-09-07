<?php

namespace Tests\Feature\Automations;

use App\Jobs\AutomationJob;
use App\Models\AutomationExecution;
use App\Models\Contacts;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Concerns\UsesFreshSchema;
use Tests\TestCase;

/**
 * B4 — contract §6.B (Correction 1), locked v1 policy:
 *
 *   CSV/BULK IMPORT-CREATED CONTACTS DO NOT FIRE CONTACT_CREATED.
 *
 * `ContactGroups::import()` bulk-inserts Contacts through raw SQL
 * (including business_id) and therefore never traverses the two
 * interactive repository seams that own the CONTACT_CREATED dispatch. This
 * test runs the REAL import path against a Business with an active
 * CONTACT_CREATED automation and proves nothing is enqueued and nothing
 * executes.
 *
 * Isolation: the import creates and drops a real `__tmp_subscribers`
 * table, and MySQL DDL implicitly commits, so this runs on a fresh schema
 * (see UsesFreshSchema) rather than inside a per-test transaction.
 */
class AutomationsImportPolicyTest extends TestCase
{
    use CreatesAutomationFixtures;
    use UsesFreshSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpFreshSchema();
    }

    public function test_import_created_business_contacts_do_not_fire_contact_created(): void
    {
        [, $business] = $this->entitledTenant();
        $channel = $this->sendableChannel($business);
        $automation = $this->sendMessageAutomation($business, $channel['server'], $channel['sender']);
        $group = $this->contactGroup($business, 'Imported');
        $this->mockSendCore(0);

        $csv = tempnam(sys_get_temp_dir(), 'b4-import-') . '.csv';
        file_put_contents($csv, implode("\n", [
            'PHONE,FIRST_NAME,LAST_NAME',
            '12025558001,Ada,Lovelace',
            '12025558002,Grace,Hopper',
            '12025558003,Edsger,Dijkstra',
        ]) . "\n");

        // The explicit header → field map the import job supplies
        // (ImportContacts passes `$this->map`); the phone field is mandatory.
        $map = $group->contactGroupFields()->get()
            ->whereIn('tag', ['PHONE', 'FIRST_NAME', 'LAST_NAME'])
            ->mapWithKeys(fn ($field) => [$field->tag => $field->id])
            ->all();

        $this->assertArrayHasKey('PHONE', $map);

        Queue::fake();

        try {
            $group->fresh()->import($csv, $map);
        } finally {
            @unlink($csv);
        }

        $imported = Contacts::query()->where('group_id', $group->id)->get();

        $this->assertCount(3, $imported, 'The real import path must have created the Business-scoped contacts.');
        $this->assertTrue($imported->every(fn (Contacts $contact) => (int) $contact->business_id === (int) $business->id));

        Queue::assertNotPushed(AutomationJob::class);
        $this->assertSame(0, AutomationExecution::query()->where('automation_id', $automation->id)->count());
        $this->assertSame(0, AutomationExecution::query()->count());
    }
}
