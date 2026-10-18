<?php

namespace Tests\Feature\Documents\Editor;

use App\Models\ContactGroupFields;
use App\Models\ContactsCustomField;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Catalog\Concerns\CreatesCatalogHttpFixtures;
use Tests\Feature\Documents\Concerns\CreatesDocumentsTestData;
use Tests\Feature\Documents\Concerns\SendsDocuments;
use Tests\TestCase;

/**
 * Contract 17B §7 — the contact date-prefill candidates and the documents-scoped
 * contact picker.
 */
class DocumentEditorPickersTest extends TestCase
{
    use RefreshDatabase;
    use SendsDocuments;
    use CreatesDocumentsTestData;
    use CreatesCatalogHttpFixtures;
    use EditorTestHelpers;

    private function appointment(array $tenant, string $status, Carbon $start, ?int $contactId = null): void
    {
        $bookingType = DB::table('booking_types')->insertGetId([
            'uid' => (string) Str::uuid(), 'public_booking_uuid' => (string) Str::uuid(),
            'business_location_id' => $tenant['location']->id, 'name' => 'Consultation', 'duration_minutes' => 30,
            'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('appointments')->insert([
            'uid' => (string) Str::uuid(), 'business_location_id' => $tenant['location']->id, 'booking_type_id' => $bookingType,
            'staff_user_id' => $tenant['customer']->user->id, 'contact_id' => $contactId ?? $tenant['contact']->id,
            'status' => $status, 'start_at' => $start, 'end_at' => $start->copy()->addMinutes(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dateField(array $tenant, string $label, ?string $value, string $type = 'date'): ContactGroupFields
    {
        $field = ContactGroupFields::create([
            'contact_group_id' => $tenant['contact']->group_id, 'label' => $label, 'type' => $type,
            'tag' => strtoupper(Str::slug($label, '_')),
        ]);
        if ($value !== null) {
            ContactsCustomField::create(['field_id' => $field->id, 'contact_id' => $tenant['contact']->id, 'value' => $value]);
        }

        return $field;
    }

    public function test_with_nothing_on_file_there_are_no_candidates(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);

        $this->getJson($this->ed('contact.dates', $tenant, $document))->assertOk()->assertJsonPath('dates', []);
    }

    public function test_the_next_scheduled_future_appointment_is_a_labelled_candidate(): void
    {
        $tenant = $this->editorTenant();
        DB::table('businesses')->where('id', $tenant['business']->id)->update(['timezone' => 'America/New_York']);
        $document = $this->blankDocument($tenant);

        $this->appointment($tenant, 'scheduled', now()->subDays(3));      // past: ignored
        $this->appointment($tenant, 'cancelled', now()->addDays(2));      // not scheduled: ignored
        $later = Carbon::parse('2030-06-20 02:30:00', 'UTC');              // 22:30 on the 19th in New York
        $this->appointment($tenant, 'scheduled', $later);
        $this->appointment($tenant, 'scheduled', Carbon::parse('2030-06-10 15:00:00', 'UTC'));

        $dates = $this->getJson($this->ed('contact.dates', $tenant, $document))->assertOk()->json('dates');

        $this->assertCount(1, $dates);
        $this->assertSame('appointment', $dates[0]['source']);
        $this->assertSame('Next appointment', $dates[0]['label']);
        $this->assertSame('2030-06-10', $dates[0]['date']);
        $this->assertStringStartsWith('2030-06-10T11:00:00', $dates[0]['iso']);
    }

    public function test_a_single_date_custom_field_is_offered_after_the_appointment(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);
        $this->dateField($tenant, 'Event date', '2031-09-05');

        $dates = $this->getJson($this->ed('contact.dates', $tenant, $document))->json('dates');
        $this->assertCount(1, $dates);
        $this->assertSame(['custom_field', 'Event date', '2031-09-05'], [$dates[0]['source'], $dates[0]['label'], $dates[0]['date']]);

        $this->appointment($tenant, 'scheduled', now()->addDays(5));
        $dates = $this->getJson($this->ed('contact.dates', $tenant, $document))->json('dates');
        $this->assertSame(['appointment', 'custom_field'], array_column($dates, 'source'));
    }

    public function test_ambiguous_or_unparseable_custom_fields_are_never_guessed(): void
    {
        $tenant = $this->editorTenant();
        $document = $this->blankDocument($tenant);

        $this->dateField($tenant, 'Event date', '2031-09-05');
        $this->dateField($tenant, 'Birthday', '1990-01-01');
        $this->getJson($this->ed('contact.dates', $tenant, $document))->assertJsonPath('dates', []); // two date fields: no guess

        DB::table('contacts_custom_field')->delete();
        ContactGroupFields::where('label', 'Birthday')->delete();
        ContactsCustomField::create(['field_id' => ContactGroupFields::where('label', 'Event date')->value('id'), 'contact_id' => $tenant['contact']->id, 'value' => 'sometime in the spring']);
        $this->getJson($this->ed('contact.dates', $tenant, $document))->assertJsonPath('dates', []);
    }

    public function test_the_contact_picker_is_documents_scoped_and_never_returns_a_foreign_contact(): void
    {
        $tenant = $this->editorTenant('Mine');
        $other = $this->sendableTenant('Theirs');
        $this->authenticateAs($tenant['customer']);
        $mine = substr((string) $tenant['contact']->phone, -6);

        $results = $this->getJson($this->ed('contacts.search', $tenant) . '?q=' . $mine)->assertOk()->assertJsonPath('status', 'ok')->json('results');
        $this->assertSame([$tenant['contact']->uid], array_column($results, 'uid'));
        $this->assertStringContainsString($tenant['contact']->phone, $results[0]['text']);

        $theirs = substr((string) $other['contact']->phone, -6);
        $this->assertSame([], $this->getJson($this->ed('contacts.search', $tenant) . '?q=' . $theirs)->assertOk()->json('results'));
    }
}
